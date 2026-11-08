<?php

namespace App\Console\Commands;

use App\Services\PrivatePrescriptionStorage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Strictly synthetic staging utility. No deletion or relocation of historical
 * public files, no access to production, and no identifiers printed.
 * Public URL shutoff is a separate release gate after client cutover.
 */
class StageLegacyPrescriptions extends Command
{
    protected $signature = 'medical-files:stage-prescriptions
        {--apply : Write private copies and change database references}
        {--rollback : With --apply, restore legacy references using the ledger}
        {--confirm-synthetic-staging : Explicit synthetic-only acknowledgement}
        {--limit=50 : Number of records to inspect per invocation}';

    protected $description = 'Safely stage or roll back synthetic legacy prescription PDFs (never production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing production access: use an independently approved operator playbook.');
            return self::FAILURE;
        }
        if (! Schema::hasTable('prescription')
            || ! Schema::hasTable('medical_prescription_migrations')) {
            $this->error('Required staging migration tables are missing.');
            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Limit must be an integer between 1 and 500.');
            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $rollback = (bool) $this->option('rollback');
        if ($apply && (! app()->environment(['local', 'testing', 'staging'])
            || ! config('medical_files.allow_synthetic_migration', false)
            || ! $this->option('confirm-synthetic-staging'))) {
            $this->error('Writes require an explicitly enabled, confirmed synthetic staging environment.');
            return self::FAILURE;
        }

        $query = $rollback
            ? DB::table('medical_prescription_migrations')->where('state', 'staged')
            : DB::table('prescription')->whereNotNull('pdf')->where('pdf', '!=', '')
                ->where('pdf', 'not like', 'k3priv_%');
        $rows = $query->orderBy('id')->limit($limit)->get();
        $scanned = 0;
        $changed = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $scanned++;
            try {
                if ($rollback) {
                    if ($apply) {
                        $this->restoreReference($row);
                        $changed++;
                    }
                    continue;
                }

                if (DB::table('medical_prescription_migrations')
                    ->where('prescription_id', $row->id)->exists()
                    || ! $this->validLegacySource((string) $row->pdf)) {
                    $skipped++;
                    continue;
                }
                if ($apply) {
                    $this->stageReference($row);
                    $changed++;
                }
            } catch (Throwable $exception) {
                // Never print filenames, patient IDs, raw exception messages
                // or other material that can identify clinical records.
                $skipped++;
            }
        }

        $this->line(sprintf(
            'Aggregate only: inspected=%d, %s=%d, skipped=%d',
            $scanned, $rollback ? 'restored' : 'staged', $changed, $skipped
        ));
        $this->warn('Original public documents remain intact; never infer that old URLs are blocked.');
        $this->warn('No filename, patient identifier, document body or full path has been logged.');
        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function stageReference(object $row): void
    {
        $legacy = (string) $row->pdf;
        $source = $this->legacyPath($legacy);
        if ($source === null) {
            throw new RuntimeException('Unsafe source.');
        }
        $hash = hash_file('sha256', $source);
        if ($hash === false) {
            throw new RuntimeException('Unable to verify source.');
        }

        $private = null;
        $store = new PrivatePrescriptionStorage();
        try {
            // tempnam creates a 0600 file under a checked private storage root.
            // The source remains intact while UploadedFile::move moves only its copy.
            $storageRoot = realpath(storage_path('app'));
            $publicRoot = realpath(public_path());
            if ($storageRoot === false || $publicRoot === false
                || $storageRoot === $publicRoot
                || str_starts_with($storageRoot, $publicRoot.DIRECTORY_SEPARATOR)
                || is_link(storage_path('app'))) {
                throw new RuntimeException('Private storage is not isolated.');
            }
            $temp = tempnam($storageRoot, 'k3migrate-');
            if ($temp === false) {
                throw new RuntimeException('No secure staging temp file.');
            }
            try {
                if (! copy($source, $temp) || ! hash_equals($hash, hash_file('sha256', $temp) ?: '')) {
                    throw new RuntimeException('Copy digest mismatch.');
                }
                $private = $store->store(new UploadedFile(
                    $temp, 'legacy-prescription.pdf', 'application/pdf', null, true
                ));
            } finally {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }

            DB::transaction(function () use ($row, $legacy, $private, $hash) {
                $changed = DB::table('prescription')->where('id', $row->id)
                    ->where('pdf', $legacy)->update(['pdf' => $private]);
                if ($changed !== 1) {
                    throw new RuntimeException('Record changed during copy.');
                }
                DB::table('medical_prescription_migrations')->insert([
                    'prescription_id' => $row->id,
                    'legacy_filename' => $legacy,
                    'private_filename' => $private,
                    'sha256' => $hash,
                    'state' => 'staged',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($private !== null) {
                $store->discard($private);
            }
            throw $exception;
        }
    }

    private function restoreReference(object $row): void
    {
        $source = $this->legacyPath((string) $row->legacy_filename);
        $private = (string) $row->private_filename;
        if ($source === null || ! hash_equals((string) $row->sha256, hash_file('sha256', $source) ?: '')
            || ! PrivatePrescriptionStorage::isPrivateFilename($private)
            || ! is_file(storage_path('app/private/medical/prescriptions/'.$private))) {
            throw new RuntimeException('Rollback proof failed.');
        }
        DB::transaction(function () use ($row, $private) {
            $changed = DB::table('prescription')->where('id', $row->prescription_id)
                ->where('pdf', $private)->update(['pdf' => $row->legacy_filename]);
            if ($changed !== 1) {
                throw new RuntimeException('Record changed before rollback.');
            }
            DB::table('medical_prescription_migrations')->where('id', $row->id)
                ->where('state', 'staged')->update(['state' => 'rolled_back', 'updated_at' => now()]);
        });
        // Private copy is deliberately retained for audit; never unlink a clinical file here.
    }

    private function validLegacySource(string $filename): bool
    {
        return $this->legacyPath($filename) !== null;
    }

    private function legacyPath(string $filename): ?string
    {
        if (! preg_match('/\\A[a-zA-Z0-9_-]{8,100}\\.pdf\\z/D', $filename)
            || PrivatePrescriptionStorage::isPrivateFilename($filename)) {
            return null;
        }
        $root = realpath(public_path('prescription/upload'));
        $path = public_path('prescription/upload/'.$filename);
        $resolved = realpath($path);
        if ($root === false || $resolved === false
            || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
            || is_link($path) || ! is_file($path)) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size < 5 || $size > 10 * 1024 * 1024) {
            return null;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            return fread($handle, 5) === '%PDF-' ? $resolved : null;
        } finally {
            fclose($handle);
        }
    }
}
