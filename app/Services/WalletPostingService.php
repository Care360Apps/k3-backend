<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WalletPostingService
{
    // Internal accounting primitive only. Never expose directly to a client.
    // Positive entries increase account balances; negative entries decrease them.
    public function post(string $key, string $kind, string $currency, array $entries): int
    {
        if (! config('care360_wallet.enabled', false)) {
            throw ValidationException::withMessages(['wallet' => 'Wallet unavailable.']);
        }
        if (! preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $key)
            || ! in_array($kind, ['funding', 'checkout', 'reversal'], true)
            || $currency !== 'NGN' || count($entries) !== 2) {
            $this->reject();
        }
        foreach ($entries as $id => $amount) {
            if (! is_int($id) || $id <= 0 || ! is_int($amount)
                || $amount === 0 || $amount < -100000000000 || $amount > 100000000000) {
                $this->reject();
            }
        }
        if (array_sum($entries) !== 0) {
            $this->reject();
        }
        ksort($entries, SORT_NUMERIC);
        $hash = hash('sha256', json_encode([$kind, $currency, $entries], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($key, $kind, $currency, $entries, $hash) {
            $accounts = DB::table('care360_wallet_accounts')->whereIn('id', array_keys($entries))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($accounts->count() !== count($entries)) {
                $this->reject();
            }
            $existing = DB::table('care360_wallet_journals')->where('idempotency_key', $key)
                ->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    $this->reject();
                }
                return (int) $existing->id;
            }
            $customerCount = 0;
            foreach ($entries as $id => $amount) {
                $account = $accounts[$id];
                if ($account->currency !== $currency) {
                    $this->reject();
                }
                if ($account->kind === 'customer') {
                    $customerCount++;
                    if ((int) $account->user_id <= 0) {
                        $this->reject();
                    }
                    // Locking read avoids a stale snapshot under MySQL repeatable read.
                    $balance = 0;
                    foreach (DB::table('care360_wallet_entries')->where('account_id', $id)
                        ->lockForUpdate()->cursor() as $entry) {
                        $balance += (int) $entry->signed_minor;
                        if (! is_int($balance)) {
                            $this->reject();
                        }
                    }
                    if (! is_int($balance + $amount) || $balance + $amount < 0) {
                        $this->reject();
                    }
                    if (($kind === 'funding' && $amount < 0) || ($kind === 'checkout' && $amount > 0)) {
                        $this->reject();
                    }
                } elseif ($account->kind !== 'system' || $account->user_id !== null) {
                    $this->reject();
                }
            }
            if ($customerCount !== 1) {
                $this->reject();
            }
            $journal = DB::table('care360_wallet_journals')->insertGetId([
                'idempotency_key' => $key, 'kind' => $kind, 'currency' => $currency,
                'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($entries as $id => $amount) {
                DB::table('care360_wallet_entries')->insert([
                    'journal_id' => $journal, 'account_id' => $id,
                    'signed_minor' => $amount, 'created_at' => now(),
                ]);
            }
            return (int) $journal;
        }, 3);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['wallet' => 'Wallet transaction rejected.']);
    }
}
