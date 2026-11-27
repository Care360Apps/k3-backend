<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\PrivateAppointmentReportStorage;
use Illuminate\Console\Command;

class AuditReportRevocationReadiness extends Command
{
    protected $signature = 'medical:report-revocation-readiness';
    protected $description = 'Check report references and private copies before separately approved public revocation';

    public function handle(): int
    {
        $folder = storage_path('app/private/medical/appointment-reports');
        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        $base = realpath($folder);
        if ($root === false || $public === false || $base === false
            || ! str_starts_with($base, $root.DIRECTORY_SEPARATOR)
            || $base === $public || str_starts_with($base, $public.DIRECTORY_SEPARATOR)) {
            $this->error('Private storage unavailable or unsafe.');
            return self::FAILURE;
        }
        $path = $root;
        foreach (['private', 'medical', 'appointment-reports'] as $part) {
            $path .= DIRECTORY_SEPARATOR.$part;
            if (is_link($path)) {
                $this->error('Unsafe private storage path.');
                return self::FAILURE;
            }
        }
        $counts = ['appointments' => 0, 'private' => 0, 'legacy' => 0, 'invalid' => 0, 'missing_or_unsafe' => 0];
        try {
            Appointment::withoutGlobalScopes()->select('id', 'report_image')->chunkById(200, function ($rows) use (&$counts, $base) {
                foreach ($rows as $row) {
                    $raw = $row->getRawOriginal('report_image');
                    if ($raw === null || $raw === '') {
                        continue;
                    }
                    $counts['appointments']++;
                    $names = json_decode($raw, true);
                    if (! is_array($names) || ! array_is_list($names)) {
                        $counts['invalid']++;
                        continue;
                    }
                    foreach ($names as $name) {
                        if (! is_string($name)) {
                            $counts['invalid']++;
                            continue;
                        }
                        if (! PrivateAppointmentReportStorage::isPrivateFilename($name)) {
                            $counts['legacy']++;
                            continue;
                        }
                        $file = $base.DIRECTORY_SEPARATOR.$name;
                        if (is_link($file) || ! is_file($file) || ! is_readable($file)
                            || realpath($file) !== $file || (fileperms($file) & 0777) !== 0600) {
                            $counts['missing_or_unsafe']++;
                            continue;
                        }
                        $counts['private']++;
                    }
                }
            });
        } catch (\Throwable $error) {
            $this->error('Readiness audit incomplete.');
            return self::FAILURE;
        }
        foreach ($counts as $label => $count) {
            $this->line($label.': '.$count);
        }
        $this->warn('Read-only check. Does not prove legacy file identity, HTTP denial or client compatibility.');
        return ($counts['legacy'] || $counts['invalid'] || $counts['missing_or_unsafe']) ? self::FAILURE : self::SUCCESS;
    }
}
