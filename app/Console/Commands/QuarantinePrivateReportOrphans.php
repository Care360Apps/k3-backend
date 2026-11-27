<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\PrivateAppointmentReportStorage;
use Illuminate\Console\Command;

class QuarantinePrivateReportOrphans extends Command
{
    protected $signature = 'medical:report-orphan-quarantine {--apply}';
    protected $description = 'Quarantine aged unreferenced private reports in isolated synthetic staging';

    public function handle(): int
    {
        if (! $this->option('apply')) {
            return $this->call('medical:report-orphan-audit');
        }
        if (! app()->environment('staging') || ! config('medical_files.allow_synthetic_migration', false) || ! app()->isDownForMaintenance()) {
            $this->error('Apply requires isolated staging, explicit synthetic enablement and maintenance mode.');
            return self::FAILURE;
        }
        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        if ($root === false || $public === false || $root === $public || str_starts_with($root, $public.DIRECTORY_SEPARATOR)) {
            return self::FAILURE;
        }
        $parent = $root;
        foreach (['private', 'medical'] as $part) {
            $parent .= DIRECTORY_SEPARATOR.$part;
            if (is_link($parent) || ! is_dir($parent) || realpath($parent) !== $parent) {
                return self::FAILURE;
            }
        }
        $source = $parent.'/appointment-reports';
        $target = $parent.'/report-quarantine';
        if (is_link($source) || ! is_dir($source) || realpath($source) !== $source || is_link($target)) {
            return self::FAILURE;
        }
        if ((! is_dir($target) && ! mkdir($target, 0700)) || realpath($target) !== $target || (fileperms($target) & 0777) !== 0700) {
            return self::FAILURE;
        }
        $moved = 0;
        try {
            foreach (new \DirectoryIterator($source) as $entry) {
                $name = $entry->getFilename();
                if ($entry->isDot() || $entry->isLink() || ! $entry->isFile()
                    || ! PrivateAppointmentReportStorage::isPrivateFilename($name)
                    || $entry->getMTime() >= time() - 7 * 86400) {
                    continue;
                }
                if (Appointment::withoutGlobalScopes()->where('report_image', 'like', '%'.$name.'%')->exists()) {
                    continue;
                }
                $from = $source.'/'.$name;
                $to = $target.'/'.$name;
                if (realpath($from) !== $from || (fileperms($from) & 0777) !== 0600
                    || file_exists($to) || is_link($to)) {
                    throw new \RuntimeException('Unsafe quarantine candidate');
                }
                // Hard link first: never overwrite a quarantine copy.
                if (! link($from, $to)) {
                    throw new \RuntimeException('Quarantine copy failed');
                }
                if (Appointment::withoutGlobalScopes()->where('report_image', 'like', '%'.$name.'%')->exists()) {
                    throw new \RuntimeException('Reference changed; both copies retained');
                }
                if (! unlink($from)) {
                    throw new \RuntimeException('Source removal failed; quarantine retained');
                }
                $moved++;
            }
        } catch (\Throwable $error) {
            $this->error('Quarantine interrupted; inspect retained copies before retry.');
            $this->line('Quarantined: '.$moved);
            return self::FAILURE;
        }
        $this->line('Quarantined: '.$moved);
        return self::SUCCESS;
    }
}
