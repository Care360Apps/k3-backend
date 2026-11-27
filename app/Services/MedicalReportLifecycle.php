<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Restartable filesystem/DB state machine. Never destroys the last verified copy. */
final class MedicalReportLifecycle
{
    public function __construct(private MedicalReportReleaseGate $gate, private PrivateAppointmentReportStorage $storage) {}

    private function folder(string $name): string
    {
        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        if (! $root || ! $public || $root === $public || str_starts_with($root, $public.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Unsafe private root.');
        }
        $path = $root;
        foreach (['private', 'medical', $name] as $part) {
            $path .= DIRECTORY_SEPARATOR.$part;
            if (is_link($path) || (! is_dir($path) && ! mkdir($path, 0700)) || realpath($path) !== $path || ! chmod($path, 0700)) {
                throw new RuntimeException('Unsafe storage directory.');
            }
        }

        return $path;
    }

    private function legacyRoot(): string
    {
        $public = realpath(public_path());
        $path = public_path('images/upload');
        if (! $public || is_link($path) || realpath($path) !== $public.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'upload') {
            throw new RuntimeException('Unsafe legacy root.');
        }

        return realpath($path);
    }

    private function validLegacy(string $name): bool
    {
        return preg_match('/\A[a-zA-Z0-9_-]+\.png\z/D', $name) === 1;
    }

    private function checked(string $folder, string $name, ?string $sha = null): string
    {
        $file = $folder.DIRECTORY_SEPARATOR.$name;
        if (is_link($file) || realpath($file) !== $file || ! is_file($file) || ! is_readable($file)
            || ((stat($file)['nlink'] ?? 1) > 1)
            || ($sha !== null && ! hash_equals($sha, (string) hash_file('sha256', $file)))) {
            throw new RuntimeException('Missing or changed report.');
        }

        return $file;
    }

    private function names($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $names = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($names) || ! array_is_list($names) || count($names) > 1000) {
            throw new RuntimeException('Invalid report metadata.');
        }
        foreach ($names as $name) {
            if (! is_string($name) || (! $this->validLegacy($name) && ! PrivateAppointmentReportStorage::isPrivateFilename($name))) {
                throw new RuntimeException('Invalid report name.');
            }
        }

        return $names;
    }

    private function copy(string $from, string $to, string $sha): void
    {
        if (is_link($to)) {
            throw new RuntimeException('Unsafe recovery destination.');
        }
        if (file_exists($to)) {
            if (realpath($to) !== $to || ! hash_equals($sha, (string) hash_file('sha256', $to))) {
                throw new RuntimeException('Conflicting recovery copy.');
            }

            return;
        }
        $in = fopen($from, 'rb');
        $out = fopen($to, 'xb');
        if (! $in || ! $out) {
            if (is_resource($in)) {
                fclose($in);
            } if (is_resource($out)) {
                fclose($out);
            } throw new RuntimeException('Copy unavailable.');
        }
        try {
            if (! chmod($to, 0600) || stream_copy_to_stream($in, $out) === false || ! fflush($out)) {
                throw new RuntimeException('Copy interrupted; preserve evidence.');
            }
            if (function_exists('fsync') && ! fsync($out)) {
                throw new RuntimeException('Copy not durable.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        if (! hash_equals($sha, (string) hash_file('sha256', $to)) || ! hash_equals($sha, (string) hash_file('sha256', $from))) {
            throw new RuntimeException('Copy integrity failed.');
        }
    }

    private function event(string $action, ?int $id = null): void
    {
        DB::table('medical_report_release_events')->insert(['action' => $action, 'operation_id' => $id, 'created_at' => now()]);
    }

    /** Shared uploads: any unknown string/JSON reference blocks revocation conservatively. */
    private function legacyReferenced(string $name): bool
    {
        foreach (Schema::getTables() as $table) {
            $tableName = $table['name'];
            if (in_array($tableName, ['appointment_report_migration_ledger', 'medical_report_file_operations', 'medical_report_release_events'], true) || str_starts_with($tableName, 'sqlite_')) {
                continue;
            }
            $columns = array_filter(Schema::getColumns($tableName), fn ($c) => preg_match('/char|text|json/i', $c['type_name']));
            if (! $columns) {
                continue;
            }
            if (DB::table($tableName)->where(function ($q) use ($columns, $name) {
                foreach ($columns as $column) {
                    $q->orWhere($column['name'], 'like', '%'.$name.'%');
                }
            })->exists()) {
                return true;
            }
        }

        return false;
    }

    public function migrate(): int
    {
        $this->gate->authorize('migrate');

        return ReportStorageLock::run(function () {
            $root = $this->legacyRoot();
            $count = 0;
            Appointment::withoutGlobalScopes()->select('id')->chunkById(100, function ($rows) use ($root, &$count) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $root, &$count) {
                        $a = Appointment::withoutGlobalScopes()->lockForUpdate()->findOrFail($row->id);
                        $names = $this->names($a->getRawOriginal('report_image'));
                        $updated = [];
                        $mapped = [];
                        foreach ($names as $name) {
                            if (PrivateAppointmentReportStorage::isPrivateFilename($name)) {
                                $this->checked($this->folder('appointment-reports'), $name);
                                $updated[] = $name;

                                continue;
                            }
                            if (isset($mapped[$name])) {
                                $updated[] = $mapped[$name];

                                continue;
                            }
                            $source = $this->checked($root, $name);
                            $bytes = file_get_contents($source, false, null, 0, 6 * 1024 * 1024 + 1);
                            if ($bytes === false) {
                                throw new RuntimeException('Unreadable source.');
                            }
                            $sha = hash('sha256', $bytes);
                            $private = $this->storage->store($bytes);
                            $this->checked($this->folder('appointment-reports'), $private, $sha);
                            $this->checked($root, $name, $sha);
                            DB::table('appointment_report_migration_ledger')->insert(['appointment_id' => $a->id, 'legacy_filename' => $name, 'private_filename' => $private, 'sha256' => $sha, 'created_at' => now()]);
                            $updated[] = $private;
                            $mapped[$name] = $private;
                        }
                        if ($updated !== $names) {
                            DB::table('appointment')->where('id', $a->id)->update(['report_image' => json_encode($updated, JSON_THROW_ON_ERROR)]);
                            $count++;
                        }
                    });
                }
            });
            $this->event('migrate');

            return $count;
        });
    }

    /** Works before AND after revocation. Recovery evidence remains mandatory. */
    public function verify(): int
    {
        $root = $this->legacyRoot();
        $private = $this->folder('appointment-reports');
        $q = $this->folder('legacy-report-quarantine');
        $count = 0;
        foreach (DB::table('appointment_report_migration_ledger')->orderBy('id')->cursor() as $row) {
            if (! $this->validLegacy($row->legacy_filename) || ! PrivateAppointmentReportStorage::isPrivateFilename($row->private_filename) || ! preg_match('/\A[a-f0-9]{64}\z/D', $row->sha256)) {
                throw new RuntimeException('Invalid ledger.');
            }
            $a = Appointment::withoutGlobalScopes()->find($row->appointment_id);
            if (! $a || ! in_array($row->private_filename, $this->names($a->getRawOriginal('report_image')), true)) {
                throw new RuntimeException('Ledger reference mismatch.');
            }
            $this->checked($private, $row->private_filename, $row->sha256);
            if (file_exists($root.DIRECTORY_SEPARATOR.$row->legacy_filename) || is_link($root.DIRECTORY_SEPARATOR.$row->legacy_filename)) {
                $this->checked($root, $row->legacy_filename, $row->sha256);
            } else {
                $op = DB::table('medical_report_file_operations')->where('kind', 'legacy')->where('filename', $row->legacy_filename)->first();
                if (! $op || $op->sha256 !== $row->sha256) {
                    throw new RuntimeException('Missing recovery evidence.');
                }
                $this->checked($q, $row->legacy_filename, $row->sha256);
            }
            $count++;
        }

        return $count;
    }

    public function revoke(): int
    {
        $this->gate->authorize('revoke');

        return ReportStorageLock::run(function () {
            if ($this->verify() === 0) {
                throw new RuntimeException('No verified reports.');
            }
            $root = $this->legacyRoot();
            $q = $this->folder('legacy-report-quarantine');
            $count = 0;
            foreach (DB::table('appointment_report_migration_ledger')->select('legacy_filename', 'sha256')->distinct()->get() as $row) {
                if ($this->legacyReferenced($row->legacy_filename)) {
                    throw new RuntimeException('Shared or ambiguous legacy upload remains referenced.');
                }
                $op = DB::table('medical_report_file_operations')->where('kind', 'legacy')->where('filename', $row->legacy_filename)->first();
                if (! $op) {
                    $id = DB::table('medical_report_file_operations')->insertGetId(['kind' => 'legacy', 'filename' => $row->legacy_filename, 'sha256' => $row->sha256, 'state' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);
                    $op = DB::table('medical_report_file_operations')->find($id);
                    $this->event('revocation_prepared', $id);
                }
                if ($op->sha256 !== $row->sha256) {
                    throw new RuntimeException('Conflicting revocation evidence.');
                }
                $source = $root.DIRECTORY_SEPARATOR.$row->legacy_filename;
                $target = $q.DIRECTORY_SEPARATOR.$row->legacy_filename;
                if (file_exists($source) || is_link($source)) {
                    $this->checked($root, $row->legacy_filename, $row->sha256);
                    $this->copy($source, $target, $row->sha256);
                    if ($this->legacyReferenced($row->legacy_filename)) {
                        throw new RuntimeException('Reference changed; original retained.');
                    }
                    $this->checked($root, $row->legacy_filename, $row->sha256);
                    if (! unlink($source)) {
                        throw new RuntimeException('Original retained; revocation incomplete.');
                    }
                } else {
                    $this->checked($q, $row->legacy_filename, $row->sha256);
                }
                if ($op->state !== 'quarantined') {
                    DB::table('medical_report_file_operations')->where('id', $op->id)->update(['state' => 'quarantined', 'http_verified_at' => null, 'updated_at' => now()]);
                    $this->event('revoked', $op->id);
                }
                $count++;
            }

            return $count;
        });
    }

    public function verifyHttp(): int
    {
        return ReportStorageLock::run(function () {
            if ($this->verify() === 0) {
                throw new RuntimeException('No verified reports.');
            }
            $origins = config('medical_files.revocation_origins', []);
            if (! $origins || ! in_array(rtrim(config('app.url'), '/'), array_map(fn ($s) => rtrim($s, '/'), $origins), true)) {
                throw new RuntimeException('Origin coverage incomplete.');
            }
            foreach ($origins as $origin) {
                if (! SafePartnerLink::isValidHttps($origin) || (parse_url($origin, PHP_URL_PATH) ?: '/') !== '/' || parse_url($origin, PHP_URL_QUERY) || parse_url($origin, PHP_URL_FRAGMENT)) {
                    throw new RuntimeException('Invalid verification origin.');
                }
            }
            $ops = DB::table('medical_report_file_operations')->where('kind', 'legacy')->get();
            DB::table('medical_report_file_operations')->where('kind', 'legacy')->update(['http_verified_at' => null]);
            if ($ops->isEmpty()) {
                throw new RuntimeException('No revoked files.');
            }
            foreach ($ops as $op) {
                if ($op->state !== 'quarantined' || file_exists($this->legacyRoot().DIRECTORY_SEPARATOR.$op->filename) || is_link($this->legacyRoot().DIRECTORY_SEPARATOR.$op->filename)) {
                    throw new RuntimeException('Public file not revoked.');
                }
                foreach ($origins as $origin) {
                    // GET rather than HEAD; redirects and positive/5xx responses never count as denial.
                    foreach ([[], ['Cache-Control' => 'no-cache']] as $headers) {
                        $response = Http::timeout(10)->withOptions(['allow_redirects' => false, 'stream' => true])->withHeaders($headers)->get(rtrim($origin, '/').'/images/upload/'.rawurlencode($op->filename));
                        try {
                            if (! in_array($response->status(), [401, 403, 404, 410], true)) {
                                throw new RuntimeException('Public URL denial not established.');
                            }
                        } finally {
                            $response->toPsrResponse()->getBody()->close();
                        }
                    }
                }
            }
            DB::table('medical_report_file_operations')->where('kind', 'legacy')->where('state', 'quarantined')->update(['http_verified_at' => now()]);
            $this->event('http_denial_verified');

            return $ops->count();
        });
    }

    public function reconcile(bool $quarantine = false): array
    {
        if ($quarantine) {
            $this->gate->authorize('quarantine');
        }

        return ReportStorageLock::run(function () use ($quarantine) {
            $source = $this->folder('appointment-reports');
            $target = $this->folder('report-quarantine');
            $counts = ['referenced' => 0, 'observed' => 0, 'quarantined' => 0, 'recent' => 0];
            // Recover the DB transition after an interrupted unlink/state update.
            foreach (DB::table('medical_report_file_operations')->where('kind', 'orphan')->where('state', 'observed')->get() as $op) {
                if (! PrivateAppointmentReportStorage::isPrivateFilename($op->filename)) {
                    throw new RuntimeException('Invalid orphan evidence.');
                }
                if (! file_exists($source.DIRECTORY_SEPARATOR.$op->filename) && file_exists($target.DIRECTORY_SEPARATOR.$op->filename)) {
                    $this->checked($target, $op->filename, $op->sha256);
                    DB::table('medical_report_file_operations')->where('id', $op->id)->update(['state' => 'quarantined', 'updated_at' => now()]);
                    $this->event('orphan_reconciled', $op->id);
                }
            }
            foreach (new \DirectoryIterator($source) as $entry) {
                $name = $entry->getFilename();
                if ($entry->isDot() || $entry->isLink() || ! $entry->isFile() || ! PrivateAppointmentReportStorage::isPrivateFilename($name)) {
                    continue;
                }
                // Ledger-backed files are retained even if a clinical record is removed later.
                if (Appointment::withoutGlobalScopes()->where('report_image', 'like', '%'.$name.'%')->exists() || DB::table('appointment_report_migration_ledger')->where('private_filename', $name)->exists()) {
                    $counts['referenced']++;

                    continue;
                }
                if ($entry->getMTime() >= time() - 7 * 86400) {
                    $counts['recent']++;

                    continue;
                }
                $file = $this->checked($source, $name);
                $sha = hash_file('sha256', $file);
                $op = DB::table('medical_report_file_operations')->where('kind', 'orphan')->where('filename', $name)->first();
                if (! $op) {
                    $id = DB::table('medical_report_file_operations')->insertGetId(['kind' => 'orphan', 'filename' => $name, 'sha256' => $sha, 'state' => 'observed', 'created_at' => now(), 'updated_at' => now()]);
                    $this->event('orphan_observed', $id);
                    $counts['observed']++;

                    continue;
                }
                if ($op->sha256 !== $sha) {
                    throw new RuntimeException('Orphan changed; retain and investigate.');
                }
                if (! $quarantine || strtotime($op->created_at) > time() - 7 * 86400 || $op->state === 'recovered') {
                    $counts['observed']++;

                    continue;
                }
                $this->copy($file, $target.DIRECTORY_SEPARATOR.$name, $sha);
                if (Appointment::withoutGlobalScopes()->where('report_image', 'like', '%'.$name.'%')->exists() || DB::table('appointment_report_migration_ledger')->where('private_filename', $name)->exists()) {
                    throw new RuntimeException('Reference changed; both copies retained.');
                }
                $this->checked($source, $name, $sha);
                if (! unlink($file)) {
                    throw new RuntimeException('Source retained.');
                }
                DB::table('medical_report_file_operations')->where('id', $op->id)->update(['state' => 'quarantined', 'updated_at' => now()]);
                $this->event('orphan_quarantined', $op->id);
                $counts['quarantined']++;
            }

            return $counts;
        });
    }

    public function recover(): int
    {
        $this->gate->authorize('recover');

        return ReportStorageLock::run(function () {
            $count = 0;
            $private = $this->folder('appointment-reports');
            foreach (DB::table('medical_report_file_operations')->where('state', 'quarantined')->orderBy('id')->get() as $op) {
                $source = $this->checked($this->folder($op->kind === 'legacy' ? 'legacy-report-quarantine' : 'report-quarantine'), $op->filename, $op->sha256);
                if ($op->kind === 'legacy') {
                    foreach (DB::table('appointment_report_migration_ledger')->where('legacy_filename', $op->filename)->get() as $row) {
                        if (! PrivateAppointmentReportStorage::isPrivateFilename($row->private_filename) || $row->sha256 !== $op->sha256) {
                            throw new RuntimeException('Invalid recovery evidence.');
                        }
                        $this->copy($source, $private.DIRECTORY_SEPARATOR.$row->private_filename, $op->sha256);
                        $count++;
                    }
                } else {
                    if (! PrivateAppointmentReportStorage::isPrivateFilename($op->filename)) {
                        throw new RuntimeException('Invalid orphan evidence.');
                    }
                    $this->copy($source, $private.DIRECTORY_SEPARATOR.$op->filename, $op->sha256);
                    DB::table('medical_report_file_operations')->where('id', $op->id)->update(['state' => 'recovered', 'updated_at' => now()]);
                    $count++;
                }
                $this->event('private_recovery', $op->id);
            }

            // Intentionally never recreates a public medical URL or silently overwrites metadata.
            return $count;
        });
    }
}
