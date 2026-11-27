<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevokeLegacyReportFiles extends Command
{
    protected $signature = 'medical:revoke-legacy-reports {--apply} {--confirm-isolated-fixtures}';
    protected $description = 'Reversibly revoke synthetic legacy report files in isolated staging';

    public function handle(): int
    {
        if (!$this->option('apply')) {
            return $this->call('medical:verify-report-ledger');
        }
        if (!app()->environment('staging') || !config('medical_files.allow_synthetic_migration', false)
            || !app()->isDownForMaintenance() || !$this->option('confirm-isolated-fixtures')) {
            $this->error('Requires isolated synthetic staging, stopped writers and maintenance mode.');
            return self::FAILURE;
        }
        if ($this->call('medical:report-revocation-readiness') !== self::SUCCESS
            || $this->call('medical:verify-report-ledger') !== self::SUCCESS) {
            return self::FAILURE;
        }
        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        $source = realpath(public_path('images/upload'));
        if (!$root || !$public || !$source || $root === $public
            || str_starts_with($root, $public.DIRECTORY_SEPARATOR)
            || $source !== $public.'/images/upload' || is_link(public_path('images/upload'))) {
            return self::FAILURE;
        }
        $target = $root.'/private/medical/legacy-report-quarantine';
        $parent = dirname($target);
        if (realpath($parent) !== $parent || is_link($root.'/private') || is_link($parent)
            || is_link($target) || (!is_dir($target) && !mkdir($target, 0700))
            || realpath($target) !== $target || (fileperms($target) & 0777) !== 0700) {
            return self::FAILURE;
        }
        $count = 0;
        try {
            // Restrict to dedicated synthetic fixture names; shared real uploads
            // require independent ownership evidence before production revocation.
            $rows = DB::table('appointment_report_migration_ledger')->select('legacy_filename', 'sha256')->distinct()->get();
            foreach ($rows as $row) {
                if (!preg_match('/\Acare360_fixture_[a-f0-9]{32}\.png\z/D', $row->legacy_filename)
                    || Appointment::withoutGlobalScopes()->where('report_image', 'like', '%'.$row->legacy_filename.'%')->exists()) {
                    throw new \RuntimeException('Unverified fixture ownership');
                }
                $from = $source.'/'.$row->legacy_filename;
                $to = $target.'/'.$row->legacy_filename;
                if (is_link($from) || realpath($from) !== $from || !is_file($from)
                    || hash_file('sha256', $from) !== $row->sha256 || file_exists($to) || is_link($to)) {
                    throw new \RuntimeException('Unsafe source or destination');
                }
            }
            foreach ($rows as $row) {
                $from = $source.'/'.$row->legacy_filename;
                $to = $target.'/'.$row->legacy_filename;
                $in = fopen($from, 'rb');
                if (!$in) {
                    throw new \RuntimeException('Source unavailable');
                }
                $out = fopen($to, 'xb');
                if (!$out) {
                    fclose($in);
                    throw new \RuntimeException('Quarantine unavailable');
                }
                try {
                    if (!chmod($to, 0600) || stream_copy_to_stream($in, $out) === false || !fflush($out)) {
                        throw new \RuntimeException('Copy failed');
                    }
                } finally {
                    fclose($in);
                    fclose($out);
                }
                if (hash_file('sha256', $to) !== $row->sha256 || hash_file('sha256', $from) !== $row->sha256
                    || Appointment::withoutGlobalScopes()->where('report_image', 'like', '%'.$row->legacy_filename.'%')->exists()
                    || !unlink($from)) {
                    throw new \RuntimeException('Revocation interrupted');
                }
                $count++;
            }
        } catch (\Throwable $error) {
            $this->error('Interrupted; preserve quarantine and inspect before retry.');
            $this->line('Revoked: '.$count);
            return self::FAILURE;
        }
        $this->line('Revoked: '.$count);
        $this->warn('HTTP denial must be verified separately.');
        return self::SUCCESS;
    }
}
