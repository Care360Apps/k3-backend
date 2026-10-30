<?php

namespace App\Console\Commands;

use App\Http\Controllers\SuperAdmin\CustomController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Medical documents used to be saved under uniqid() names, which are timestamps and can be guessed. This gives every
 * existing prescription, lab report and appointment attachment an unguessable name and updates the database rows.
 */
class RandomizeMedicalFileNames extends Command
{
    protected $signature = 'medical-files:randomize-names {--dry-run : Only report what would be renamed}';

    protected $description = 'Rename existing prescriptions, lab reports and appointment attachments to unguessable file names';

    /** [table, column, public directory] */
    private const SINGLE_FILE_COLUMNS = [
        ['prescription', 'pdf', 'prescription/upload'],
        ['purchase_medicine', 'pdf', 'prescription/upload'],
        ['report', 'prescription', 'report_prescription/upload'],
        ['report', 'upload_report', 'report_prescription/report'],
    ];

    private int $renamed = 0;

    public function handle(): int
    {
        foreach (self::SINGLE_FILE_COLUMNS as [$table, $column, $dir]) {
            DB::table($table)->whereNotNull($column)->where($column, '!=', '')->orderBy('id')->each(function ($row) use ($table, $column, $dir) {
                $new = $this->rename($dir, $row->{$column});
                if ($new !== null && ! $this->option('dry-run')) {
                    DB::table($table)->where('id', $row->id)->update([$column => $new]);
                }
            });
        }

        DB::table('appointment')->whereNotNull('report_image')->where('report_image', '!=', '')->orderBy('id')->each(function ($row) {
            $images = json_decode($row->report_image, true);
            if (! is_array($images)) {
                return;
            }
            $changed = false;
            foreach ($images as $i => $image) {
                $new = $this->rename('images/upload', (string) $image);
                if ($new !== null) {
                    $images[$i] = $new;
                    $changed = true;
                }
            }
            if ($changed && ! $this->option('dry-run')) {
                DB::table('appointment')->where('id', $row->id)->update(['report_image' => json_encode(array_values($images))]);
            }
        });

        $this->info(($this->option('dry-run') ? 'Would rename ' : 'Renamed ').$this->renamed.' file(s).');

        return self::SUCCESS;
    }

    /** Returns the new name, or null when the file is missing, outside $dir, or already random. */
    private function rename(string $dir, string $name): ?string
    {
        if ($name === basename($name) && preg_match('/^[0-9a-f]{32}\.[a-z0-9]+$/', $name)) {
            return null;
        }
        $path = public_path($dir.'/'.basename($name));
        if ($name !== basename($name) || ! is_file($path)) {
            return null;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $new = CustomController::randomFileName($extension);
        $this->renamed++;
        if ($this->option('dry-run')) {
            $this->line("{$dir}/{$name} -> {$new}");

            return $new;
        }
        rename($path, public_path($dir.'/'.$new));

        return $new;
    }
}
