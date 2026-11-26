<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AuditWalletLedger extends Command
{
    protected $signature = 'wallet:audit';
    protected $description = 'Read-only wallet integrity checks; never prints customer or receipt identifiers';

    public function handle(): int
    {
        try {
            foreach (['care360_wallet_accounts', 'care360_wallet_journals', 'care360_wallet_entries',
                'care360_wallet_funding', 'payment_receipt_claims'] as $table) {
                if (! Schema::hasTable($table)) {
                    $this->error('Required wallet schema missing.');
                    return self::FAILURE;
                }
            }
            $counts = DB::transaction(function () {
                $unbalanced = DB::table('care360_wallet_journals as j')
                    ->leftJoin('care360_wallet_entries as e', 'j.id', '=', 'e.journal_id')
                    ->groupBy('j.id')->havingRaw('COUNT(e.id) <> 2 OR COALESCE(SUM(e.signed_minor), 0) <> 0');
                $negative = DB::table('care360_wallet_accounts as a')
                    ->join('care360_wallet_entries as e', 'a.id', '=', 'e.account_id')
                    ->where('a.kind', 'customer')->groupBy('a.id')->havingRaw('SUM(e.signed_minor) < 0');
                $currencyMismatch = DB::table('care360_wallet_entries as e')
                    ->join('care360_wallet_accounts as a', 'a.id', '=', 'e.account_id')
                    ->join('care360_wallet_journals as j', 'j.id', '=', 'e.journal_id')
                    ->whereColumn('a.currency', '<>', 'j.currency')->count();
                $badFunding = DB::table('care360_wallet_funding as f')
                    ->leftJoin('care360_wallet_journals as j', 'f.journal_id', '=', 'j.id')
                    ->where('f.status', 'credited')
                    ->where(function ($q) {
                        $q->whereNull('j.id')->orWhere('j.kind', '<>', 'funding')
                            ->orWhereColumn('j.currency', '<>', 'f.currency');
                    })->count();
                $missingClaims = DB::table('care360_wallet_funding as f')
                    ->leftJoin('payment_receipt_claims as p', function ($join) {
                        $join->on('p.product_id', '=', 'f.id')->where('p.product_type', 'wallet_funding')
                            ->where('p.gateway', 'PAYSTACK')->on('p.user_id', '=', 'f.user_id');
                    })->where('f.status', 'credited')->whereNull('p.id')->count();
                return [
                    'Unbalanced or incomplete journals' => DB::query()->fromSub($unbalanced->select('j.id'), 'bad')->count(),
                    'Negative customer balances' => DB::query()->fromSub($negative->select('a.id'), 'bad')->count(),
                    'Currency mismatches' => $currencyMismatch,
                    'Invalid credited funding links' => $badFunding,
                    'Missing funding receipt claims' => $missingClaims,
                ];
            });
            foreach ($counts as $label => $count) {
                $this->line($label.': '.$count);
            }
            $this->line('Pending funding older than 30 minutes: '.DB::table('care360_wallet_funding')
                ->where('status', 'pending')->where('created_at', '<', now()->subMinutes(30))->count());
            return array_sum($counts) === 0 ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Wallet audit unavailable; inspect schema and database access privately.');
            return self::FAILURE;
        }
    }
}
