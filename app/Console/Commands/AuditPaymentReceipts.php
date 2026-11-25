<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AuditPaymentReceipts extends Command
{
    protected $signature = 'payments:audit-receipts';
    protected $description = 'Read-only aggregate payment receipt readiness audit';

    public function handle(): int
    {
        try {
            $tables = ['appointment', 'purchase_medicine', 'report', 'doctor_subscription'];
            $claims = Schema::hasTable('payment_receipt_claims');
            $this->line('Claim table present: '.($claims ? 'yes' : 'no'));
            $this->line('Atomic gate enabled: '.(config('payment_security.atomic_receipt_claims') ? 'yes' : 'no'));
            $unique = false;
            if ($claims) {
                foreach (Schema::getIndexes('payment_receipt_claims') as $index) {
                    if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['receipt_sha256']) {
                        $unique = true;
                        break;
                    }
                }
            }
            $this->line('Unique receipt hash index present: '.($unique ? 'yes' : 'no'));
            $union = null;
            $missing = 0;
            foreach ($tables as $table) {
                if (! Schema::hasColumns($table, ['payment_status', 'payment_token', 'payment_type'])) {
                    $this->error('Missing required columns: '.$table);
                    return self::FAILURE;
                }
                $paid = DB::table($table)->where('payment_status', 1)
                    ->whereRaw("UPPER(COALESCE(payment_type, '')) <> 'COD'");
                $count = (clone $paid)->where(function ($query) {
                    $query->whereNull('payment_token')->orWhereRaw("TRIM(payment_token) = ''");
                })->count();
                $missing += $count;
                $this->line($table.' paid rows without receipt: '.$count);
                $refs = (clone $paid)->whereNotNull('payment_token')
                    ->whereRaw("TRIM(payment_token) <> ''")->select('payment_token');
                $union = $union === null ? $refs : $union->unionAll($refs);
            }
            $duplicates = DB::query()->fromSub($union, 'receipts')
                ->select('payment_token')->groupBy('payment_token')->havingRaw('COUNT(*) > 1');
            $duplicateCount = DB::query()->fromSub($duplicates, 'duplicates')->count();
            $this->line('Duplicate paid receipt groups: '.$duplicateCount);
            if ($claims) {
                $this->line('Existing claim rows: '.DB::table('payment_receipt_claims')->count());
            }
            $this->warn('Aggregate audit only; ownership, backfill and concurrency still require verification.');
            return $claims && $unique && $missing === 0 && $duplicateCount === 0 ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Receipt audit unavailable; inspect schema and database connectivity privately.');
            return self::FAILURE;
        }
    }
}
