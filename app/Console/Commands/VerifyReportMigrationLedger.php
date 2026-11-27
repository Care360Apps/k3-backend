<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\PrivateAppointmentReportStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyReportMigrationLedger extends Command
{
    protected $signature = 'medical:verify-report-ledger';
    protected $description = 'Verify migration evidence without changing files';

    public function handle(): int
    {
        $legacy = realpath(public_path('images/upload'));
        $private = realpath(storage_path('app/private/medical/appointment-reports'));
        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        if (!$legacy || !$private || !$root || !$public
            || !str_starts_with($legacy, $public.DIRECTORY_SEPARATOR)
            || !str_starts_with($private, $root.DIRECTORY_SEPARATOR)
            || str_starts_with($private, $public.DIRECTORY_SEPARATOR)) {
            return self::FAILURE;
        }
        $verified = 0;
        $failed = 0;
        try {
            DB::table('appointment_report_migration_ledger')->orderBy('id')->chunkById(100, function ($rows) use ($legacy, $private, &$verified, &$failed) {
                foreach ($rows as $row) {
                    if (!preg_match('/\A[a-zA-Z0-9_-]+\.png\z/D', $row->legacy_filename)
                        || !PrivateAppointmentReportStorage::isPrivateFilename($row->private_filename)
                        || !preg_match('/\A[a-f0-9]{64}\z/D', $row->sha256)) {
                        $failed++;
                        continue;
                    }
                    $a = $legacy.DIRECTORY_SEPARATOR.$row->legacy_filename;
                    $b = $private.DIRECTORY_SEPARATOR.$row->private_filename;
                    $appointment = Appointment::withoutGlobalScopes()->find($row->appointment_id);
                    $names = $appointment ? json_decode($appointment->getRawOriginal('report_image') ?? '[]', true) : null;
                    if (!is_array($names) || !in_array($row->private_filename, $names, true)
                        || is_link($a) || is_link($b) || !is_file($a) || !is_file($b)
                        || realpath($a) !== $a || realpath($b) !== $b
                        || hash_file('sha256', $a) !== $row->sha256
                        || hash_file('sha256', $b) !== $row->sha256) {
                        $failed++;
                        continue;
                    }
                    $verified++;
                }
            });
        } catch (\Throwable $error) {
            $this->error('Ledger verification incomplete.');
            return self::FAILURE;
        }
        $this->line('Verified: '.$verified);
        $this->line('Failed: '.$failed);
        $this->warn('Verification alone does not authorize public revocation.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
