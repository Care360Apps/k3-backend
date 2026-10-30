<?php

namespace App\Services;

use App\Models\Medicine;
use App\Models\MedicineCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bulk-creates pharmacy products (medicines) from a CSV file.
 *
 * The whole file is validated first; nothing is saved unless every row is valid. A row whose name already exists
 * for the pharmacy updates that product and adds its incoming_stock as a restock (same as "change stock").
 */
class MedicineImportService
{
    public const COLUMNS = ['name', 'category', 'price_pr_strip', 'number_of_medicine', 'incoming_stock', 'description', 'works', 'prescription_required', 'status'];

    public const MAX_ROWS = 1000;

    private const REQUIRED_COLUMNS = ['name', 'category', 'price_pr_strip', 'number_of_medicine', 'incoming_stock', 'description', 'works'];

    private const ALIASES = [
        'medicine_name' => 'name', 'product_name' => 'name',
        'category_name' => 'category', 'medicine_category' => 'category',
        'price' => 'price_pr_strip', 'price_per_strip' => 'price_pr_strip',
        'medicines_per_strip' => 'number_of_medicine', 'number_of_medicines' => 'number_of_medicine',
        'stock' => 'incoming_stock', 'quantity' => 'incoming_stock',
        'how_it_works' => 'works',
        'prescription' => 'prescription_required',
    ];

    private const BOOLEAN_VALUES = ['1' => 1, '0' => 0, 'yes' => 1, 'no' => 0, 'y' => 1, 'n' => 0, 'true' => 1, 'false' => 0, 'active' => 1, 'inactive' => 0];

    public static function templateCsv(): string
    {
        $rows = [
            self::COLUMNS,
            ['Paracetamol 500mg', 'Pain Relief', '120', '10', '50', 'Pain and fever relief tablets', 'Take 1 tablet every 6 hours after meals', 'no', 'yes'],
        ];
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);

        return stream_get_contents($handle);
    }

    /**
     * @return array{created: int, updated: int}
     *
     * @throws ValidationException listing every problem found, with row numbers
     */
    public function import(UploadedFile $file, int $pharmacyId): array
    {
        [$header, $rows] = $this->read($file);

        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ($missing) {
            $this->fail([__('Missing column(s): :columns', ['columns' => implode(', ', $missing)])]);
        }
        if (! $rows) {
            $this->fail([__('The file does not contain any products.')]);
        }
        if (count($rows) > self::MAX_ROWS) {
            $this->fail([__('A file may contain at most :max products.', ['max' => self::MAX_ROWS])]);
        }

        $categories = MedicineCategory::whereStatus(1)->get(['id', 'name']);
        $categoryIdsByName = $categories->mapWithKeys(fn ($category) => [mb_strtolower(trim($category->name)) => $category->id]);

        $errors = [];
        $valid = [];
        $seenNames = [];
        foreach ($rows as $lineNumber => $values) {
            $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
            $row = array_map(fn ($value) => trim((string) $value), $row);
            foreach (['prescription_required', 'status'] as $flag) {
                if (isset($row[$flag])) {
                    $row[$flag] = mb_strtolower($row[$flag]);
                }
            }

            $validator = Validator::make($row, [
                'name' => 'required|max:255',
                'category' => 'required',
                'price_pr_strip' => 'required|numeric|min:0',
                'number_of_medicine' => 'required|integer|min:1',
                'incoming_stock' => 'required|integer|min:0',
                'description' => 'required',
                'works' => 'required',
                'prescription_required' => 'nullable|in:'.implode(',', array_keys(self::BOOLEAN_VALUES)),
                'status' => 'nullable|in:'.implode(',', array_keys(self::BOOLEAN_VALUES)),
            ]);
            foreach ($validator->errors()->all() as $message) {
                $errors[] = __('Row :row: :message', ['row' => $lineNumber, 'message' => $message]);
            }

            $categoryId = ctype_digit($row['category'] ?? '')
                ? $categories->firstWhere('id', (int) $row['category'])?->id
                : ($categoryIdsByName[mb_strtolower($row['category'] ?? '')] ?? null);
            if (($row['category'] ?? '') !== '' && ! $categoryId) {
                $errors[] = __('Row :row: unknown or inactive category ":category".', ['row' => $lineNumber, 'category' => $row['category']]);
            }

            $nameKey = mb_strtolower($row['name'] ?? '');
            if ($nameKey !== '' && isset($seenNames[$nameKey])) {
                $errors[] = __('Row :row: ":name" is already listed on row :first.', ['row' => $lineNumber, 'name' => $row['name'], 'first' => $seenNames[$nameKey]]);
            }
            $seenNames[$nameKey] = $seenNames[$nameKey] ?? $lineNumber;

            if (! $validator->fails() && $categoryId) {
                $valid[] = [
                    'name' => $row['name'],
                    'medicine_category_id' => $categoryId,
                    'price_pr_strip' => (int) round((float) $row['price_pr_strip']),
                    'number_of_medicine' => (int) $row['number_of_medicine'],
                    'incoming_stock' => (int) $row['incoming_stock'],
                    'description' => $row['description'],
                    'works' => $row['works'],
                    'prescription_required' => self::BOOLEAN_VALUES[$row['prescription_required'] ?? ''] ?? 0,
                    'status' => self::BOOLEAN_VALUES[$row['status'] ?? ''] ?? 1,
                ];
            }
        }
        if ($errors) {
            $this->fail($errors);
        }

        return DB::transaction(function () use ($valid, $pharmacyId) {
            $created = 0;
            $updated = 0;
            foreach ($valid as $data) {
                $medicine = Medicine::where('pharmacy_id', $pharmacyId)->where('name', $data['name'])->first();
                if ($medicine) {
                    $medicine->update(array_merge($data, [
                        'name' => $medicine->name,
                        'incoming_stock' => $medicine->incoming_stock + $data['incoming_stock'],
                        'total_stock' => $medicine->total_stock + $data['incoming_stock'],
                    ]));
                    $updated++;
                } else {
                    Medicine::create(array_merge($data, [
                        'pharmacy_id' => $pharmacyId,
                        'total_stock' => $data['incoming_stock'],
                        'use_stock' => 0,
                        'image' => 'prod_default.png',
                    ]));
                    $created++;
                }
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * @return array{0: string[], 1: array<int, string[]>} normalised header and data rows keyed by spreadsheet row number
     */
    private function read(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $firstLine = (string) fgets($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $header = null;
        $rows = [];
        $lineNumber = 0;
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $lineNumber++;
            if ($header === null) {
                $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $values[0]);
                $header = array_map(function ($column) {
                    $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $column)));
                    $key = trim($key, '_');

                    return self::ALIASES[$key] ?? $key;
                }, $values);

                continue;
            }
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $rows[$lineNumber] = $values;
        }
        fclose($handle);

        return [$header ?? [], $rows];
    }

    private function fail(array $errors): never
    {
        $shown = array_slice($errors, 0, 50);
        if (count($errors) > count($shown)) {
            $shown[] = __('...and :count more problem(s).', ['count' => count($errors) - count($shown)]);
        }

        throw ValidationException::withMessages(['file' => $shown]);
    }
}
