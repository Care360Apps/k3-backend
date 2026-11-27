<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\PrivateAppointmentReportStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MigrateLegacyAppointmentReports extends Command
{
    protected $signature = 'medical:migrate-appointment-reports {--apply : Copy reports and update references}';
    protected $description = 'Copy legacy reports into private storage; retain originals for separately verified revocation';

    public function handle(PrivateAppointmentReportStorage $storage): int
    {
        if (! $this->option('apply')) {
            return $this->call('medical:report-migration-audit');
        }
        if (! config('medical_files.allow_synthetic_migration', false) || app()->environment('production')) {
            $this->error('Migration apply is restricted to explicitly enabled non-production environments.');
            return self::FAILURE;
        }
        if ($this->call('medical:report-migration-audit') !== self::SUCCESS) {
            return self::FAILURE;
        }
        $root = realpath(public_path('images/upload'));
        if ($root === false || is_link(public_path('images/upload'))) {
            $this->error('Legacy storage unavailable.');
            return self::FAILURE;
        }
        $migrated = 0;
        $failed = 0;
        Appointment::query()->select('id')->chunkById(100, function ($rows) use ($root, $storage, &$migrated, &$failed) {
            foreach ($rows as $row) {
                $createdFiles = [];
                try {
                    $changed = DB::transaction(function () use ($row, $root, $storage, &$createdFiles) {
                        $appointment = Appointment::query()->lockForUpdate()->findOrFail($row->id);
                        $raw = $appointment->getRawOriginal('report_image');
                        if ($raw === null || $raw === '') {
                            return false;
                        }
                        $names = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                        if (! is_array($names) || ! array_is_list($names)) {
                            throw new RuntimeException('Invalid metadata');
                        }
                        $updated = [];
                        foreach ($names as $name) {
                            if (is_string($name) && PrivateAppointmentReportStorage::isPrivateFilename($name)) {
                                $updated[] = $name;
                                continue;
                            }
                            if (! is_string($name) || ! preg_match('/\\A[a-zA-Z0-9_-]+\\.png\\z/D', $name)) {
                                throw new RuntimeException('Invalid legacy name');
                            }
                            $source = $root.DIRECTORY_SEPARATOR.$name;
                            $resolved = realpath($source);
                            if ($resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
                                || is_link($source) || ! is_file($resolved)) {
                                throw new RuntimeException('Invalid legacy path');
                            }
                            $bytes = file_get_contents($resolved, false, null, 0, 5 * 1024 * 1024 + 1);
                            if ($bytes === false) {
                                throw new RuntimeException('Unreadable report');
                            }
                            $private = $storage->store($bytes);
                            $createdFiles[] = $private;
                            $target = storage_path('app/private/medical/appointment-reports/'.$private);
                            if (hash_file('sha256', $target) !== hash('sha256', $bytes)) {
                                throw new RuntimeException('Copy verification failed');
                            }
                            DB::table('appointment_report_migration_ledger')->insert([
                                'appointment_id' => $appointment->id,
                                'legacy_filename' => $name,
                                'private_filename' => $private,
                                'sha256' => hash('sha256', $bytes),
                                'created_at' => now(),
                            ]);
                            $updated[] = $private;
                        }
                        if ($updated === $names) {
                            return false;
                        }
                        DB::table($appointment->getTable())->where('id', $appointment->id)
                            ->update(['report_image' => json_encode($updated, JSON_THROW_ON_ERROR)]);
                        return true;
                    });
                    $migrated += (int) $changed;
                } catch (\Throwable $error) {
                    // Do not log patient identifiers, paths or report content.
                    $failed++;
                    foreach ($createdFiles as $private) {
                        try {
                            // A commit may have succeeded despite a connection error.
                            // Retain the file unless the current database proves it is unreferenced.
                            if (! Appointment::withoutGlobalScopes()->where('report_image', 'like', '%'.$private.'%')->exists()) {
                                $storage->discard($private);
                            }
                        } catch (\Throwable $cleanupError) {
                            // Preserve files if database or storage state is uncertain.
                        }
                    }
                }
            }
        });
        $this->line('Migrated appointments: '.$migrated);
        $this->line('Failed appointments: '.$failed);
        $this->warn('Original public files retained. Public access has not been revoked. Failed transactions may leave private orphan copies.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
