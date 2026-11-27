<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\PrivateAppointmentReportStorage;
use Illuminate\Console\Command;

class AuditLegacyAppointmentReports extends Command
{
    protected $signature = 'medical:report-migration-audit';
    protected $description = 'Count legacy report migration candidates without modifying records or files';

    public function handle(): int
    {
        $counts = ['appointments' => 0, 'private' => 0, 'eligible' => 0,
            'invalid' => 0, 'missing' => 0, 'malformed' => 0];
        $folder = realpath(public_path('images/upload'));
        Appointment::query()->select(['id', 'report_image'])->chunkById(200, function ($rows) use (&$counts, $folder) {
            foreach ($rows as $row) {
                $counts['appointments']++;
                $raw = $row->getRawOriginal('report_image');
                if ($raw === null || $raw === '') {
                    continue;
                }
                $names = json_decode($raw, true);
                if (! is_array($names) || ! array_is_list($names)) {
                    $counts['malformed']++;
                    continue;
                }
                foreach ($names as $name) {
                    if (is_string($name) && PrivateAppointmentReportStorage::isPrivateFilename($name)) {
                        $counts['private']++;
                        continue;
                    }
                    if (! is_string($name) || ! preg_match('/\A[a-zA-Z0-9_-]+\.png\z/D', $name)) {
                        $counts['invalid']++;
                        continue;
                    }
                    $path = $folder === false ? false : realpath($folder.DIRECTORY_SEPARATOR.$name);
                    if ($path === false || ! str_starts_with($path, $folder.DIRECTORY_SEPARATOR)
                        || ! is_file($path) || is_link($folder.DIRECTORY_SEPARATOR.$name)) {
                        $counts['missing']++;
                        continue;
                    }
                    $size = filesize($path);
                    $info = @getimagesize($path);
                    if ($size === false || $size > 5 * 1024 * 1024 || ! $info
                        || $info[2] !== IMAGETYPE_PNG || $info[0] > 10000 || $info[1] > 10000) {
                        $counts['invalid']++;
                        continue;
                    }
                    $counts['eligible']++;
                }
            }
        });
        foreach ($counts as $label => $count) {
            $this->line($label.': '.$count);
        }
        return ($counts['invalid'] || $counts['missing'] || $counts['malformed']) ? self::FAILURE : self::SUCCESS;
    }
}
