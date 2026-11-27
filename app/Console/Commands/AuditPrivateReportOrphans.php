<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\PrivateAppointmentReportStorage;
use Illuminate\Console\Command;

class AuditPrivateReportOrphans extends Command
{
    protected $signature = 'medical:report-orphan-audit';
    protected $description = 'Count private report orphan candidates without deleting or exposing files';

    public function handle(): int
    {
        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        $folder = storage_path('app/private/medical/appointment-reports');
        if ($root === false || $public === false) {
            $this->error('Storage roots unavailable.');
            return self::FAILURE;
        }
        $path = $root;
        foreach (['private', 'medical', 'appointment-reports'] as $part) {
            $path .= DIRECTORY_SEPARATOR.$part;
            if (is_link($path)) {
                $this->error('Unsafe storage path.');
                return self::FAILURE;
            }
        }
        $base = realpath($folder);
        if ($base === false || ! is_dir($base) || ! str_starts_with($base, $root.DIRECTORY_SEPARATOR)
            || $base === $public || str_starts_with($base, $public.DIRECTORY_SEPARATOR)) {
            $this->error('Private report directory unavailable or unsafe.');
            return self::FAILURE;
        }
        $counts = ['referenced' => 0, 'recent' => 0, 'candidates' => 0, 'skipped' => 0];
        $cutoff = time() - 7 * 86400;
        try {
            foreach (new \DirectoryIterator($base) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                $name = $entry->getFilename();
                if ($entry->isLink() || ! $entry->isFile() || ! PrivateAppointmentReportStorage::isPrivateFilename($name)) {
                    $counts['skipped']++;
                    continue;
                }
                if ($entry->getMTime() >= $cutoff) {
                    $counts['recent']++;
                    continue;
                }
                // Include every row, including rows hidden by model scopes.
                // Substring matches deliberately err on the side of retention.
                if (Appointment::withoutGlobalScopes()->where('report_image', 'like', '%'.$name.'%')->exists()) {
                    $counts['referenced']++;
                    continue;
                }
                $counts['candidates']++;
            }
        } catch (\Throwable $error) {
            $this->error('Audit incomplete; no files modified.');
            return self::FAILURE;
        }
        foreach ($counts as $label => $count) {
            $this->line($label.': '.$count);
        }
        $this->warn('Candidates are not proof of safe deletion. No files modified.');
        return self::SUCCESS;
    }
}
