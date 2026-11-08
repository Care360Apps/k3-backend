<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only migration preflight. Prints counts only, never filenames,
 * patient identifiers, document bytes, full paths, hashes or records.
 */
class InventoryMedicalFilesSafely extends Command
{
    protected $signature = 'medical-files:inventory-safe
        {--production-read-only : Authorized operator explicitly permits a production READ-ONLY inventory}';

    protected $description = 'Count clinical file references in public/private folders without exposing PHI';

    private const SINGLE_COLUMNS = [
        ['prescription', 'pdf', 'prescription/upload'],
        ['purchase_medicine', 'pdf', 'prescription/upload'],
        ['report', 'prescription', 'report_prescription/upload'],
        ['report', 'upload_report', 'report_prescription/report'],
    ];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('production-read-only')) {
            $this->error('Production inventory requires an authorized operator and explicit --production-read-only.');
            return self::FAILURE;
        }

        $this->warn('Counts only. No files will be copied, opened, renamed or removed.');
        $summary = [];
        foreach (self::SINGLE_COLUMNS as [$table, $column, $folder]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $this->error('Required clinical schema is missing. Stop and review migrations.');
                return self::FAILURE;
            }
            $stats = $this->newStats();
            DB::table($table)
                ->select(['id', $column])
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (&$stats, $column, $folder) {
                    foreach ($rows as $row) {
                        $this->countReference($stats, $folder, $row->{$column});
                    }
                });
            $summary[] = [$table.'.'.$column, ...array_values($stats)];
        }

        if (! Schema::hasTable('appointment') || ! Schema::hasColumn('appointment', 'report_image')) {
            $this->error('Appointment report schema is missing.');
            return self::FAILURE;
        }

        $imageStats = $this->newStats();
        DB::table('appointment')
            ->select(['id', 'report_image'])
            ->whereNotNull('report_image')
            ->where('report_image', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$imageStats) {
                foreach ($rows as $row) {
                    $names = json_decode((string) $row->report_image, true);
                    if (! is_array($names)) {
                        $imageStats['unsafe']++;
                        $imageStats['referenced']++;
                        continue;
                    }
                    foreach ($names as $name) {
                        $this->countReference($imageStats, 'images/upload', $name);
                    }
                }
            });
        $summary[] = ['appointment.report_image', ...array_values($imageStats)];

        $this->table(
            ['Clinical reference', 'Referenced', 'Public present', 'Private present', 'Missing', 'Unsafe'],
            $summary
        );
        $this->warn('These are local filesystem counts, NOT proof that old URLs are blocked over HTTP.');
        $this->warn('Do not run the legacy name-randomizer to solve public access: random names are not access control.');

        return self::SUCCESS;
    }

    private function newStats(): array
    {
        return [
            'referenced' => 0,
            'public' => 0,
            'private' => 0,
            'missing' => 0,
            'unsafe' => 0,
        ];
    }

    private function countReference(array &$stats, string $folder, mixed $value): void
    {
        $stats['referenced']++;
        if (! is_string($value)
            || $value === ''
            || $value !== basename($value)
            || ! preg_match('/\A[a-zA-Z0-9_-]{8,100}\.(?:pdf|png|jpg|jpeg|webp)\z/D', $value)) {
            $stats['unsafe']++;
            return;
        }

        $public = public_path($folder.DIRECTORY_SEPARATOR.$value);
        if (! is_link($public) && is_file($public)) {
            $stats['public']++;
            return;
        }

        if ($folder === 'prescription/upload') {
            $private = storage_path('app/private/medical/prescriptions/'.$value);
            if (! is_link($private) && is_file($private)) {
                $stats['private']++;
                return;
            }
        }
        $stats['missing']++;
    }
}
