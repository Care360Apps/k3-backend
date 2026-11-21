<?php

namespace App\Console\Commands;

use App\Models\FacilityDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportFacilityDirectoryCsv extends Command
{
    protected $signature = 'facility-directory:import {file : UTF-8 CSV file} {--commit : Create draft records (default is dry run)}';
    protected $description = 'Validate sourced facility CSV and optionally stage new records as pending drafts. Never publishes.';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (!is_file($path) || !is_readable($path)) {
            $this->error('CSV file is not readable.');
            return self::FAILURE;
        }
        if (filesize($path) > 5 * 1024 * 1024) {
            $this->error('CSV exceeds the 5 MB import limit.');
            return self::FAILURE;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) return self::FAILURE;
        $required = ['type', 'name', 'state', 'address', 'latitude', 'longitude', 'source_name', 'source_id', 'source_url'];
        $header = fgetcsv($handle);
        if (!$header || count($header) !== count(array_unique($header)) || array_diff($required, $header)) {
            fclose($handle);
            $this->error('CSV requires unique headers: '.implode(', ', $required));
            return self::FAILURE;
        }
        $rows = [];
        $errors = [];
        $seen = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if ($line > 1001) { $errors[] = 'Maximum 1000 records per import.'; break; }
            if (count($values) !== count($header)) { $errors[] = "Line $line: column count mismatch"; continue; }
            $row = array_combine($header, $values);
            $row = array_map(static fn ($v) => trim((string) $v), $row);
            $key = $row['source_name'].'|'.$row['source_id'];
            if (!in_array($row['type'], ['hospital', 'pharmacy'], true)
                || !in_array($row['state'], ['FCT', 'Nasarawa', 'Plateau'], true)
                || $row['name'] === '' || mb_strlen($row['name']) > 200
                || $row['address'] === '' || mb_strlen($row['address']) > 2000
                || $row['source_name'] === '' || mb_strlen($row['source_name']) > 120
                || $row['source_id'] === '' || mb_strlen($row['source_id']) > 200
                || !filter_var($row['source_url'], FILTER_VALIDATE_URL)
                || !in_array(parse_url($row['source_url'], PHP_URL_SCHEME), ['https'], true)
                || !is_numeric($row['latitude']) || !is_numeric($row['longitude'])
                || (float) $row['latitude'] < -90 || (float) $row['latitude'] > 90
                || (float) $row['longitude'] < -180 || (float) $row['longitude'] > 180
                || isset($seen[$key])) {
                $errors[] = "Line $line: invalid, incomplete or duplicate source record";
                continue;
            }
            $seen[$key] = true;
            $rows[] = array_intersect_key($row, array_flip($required));
        }
        fclose($handle);
        if ($errors) {
            foreach (array_slice($errors, 0, 20) as $error) $this->error($error);
            $this->error('Import rejected; no records created.');
            return self::FAILURE;
        }
        $existing = 0;
        foreach ($rows as $row) {
            if (FacilityDirectory::where('source_name', $row['source_name'])->where('source_id', $row['source_id'])->exists()) $existing++;
        }
        $this->info(count($rows)." valid records; $existing already present; ".(count($rows) - $existing)." candidates.");
        if (!$this->option('commit')) {
            $this->warn('Dry run only. Use --commit after source and rights review.');
            return self::SUCCESS;
        }
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                FacilityDirectory::firstOrCreate(
                    ['source_name' => $row['source_name'], 'source_id' => $row['source_id']],
                    array_merge($row, [
                        'review_status' => 'pending',
                        'listing_status' => 'draft',
                        'partner_status' => 'none',
                    ])
                );
            }
        });
        $this->info('Staged as pending drafts only. Administrator verification is required.');
        return self::SUCCESS;
    }
}
