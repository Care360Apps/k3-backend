<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WalletAccountService
{
    // Internal service: callers must derive user ID from authenticated identity.
    public function account(int $userId): int
    {
        $this->guard($userId);
        $key = 'customer:NGN:'.$userId;
        DB::table('care360_wallet_accounts')->insertOrIgnore([
            'account_key' => $key, 'user_id' => $userId,
            'kind' => 'customer', 'currency' => 'NGN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $account = $this->owned($userId);
        if (! $account) {
            $this->reject();
        }
        return (int) $account->id;
    }

    public function balance(int $userId): array
    {
        $this->guard($userId);
        $account = $this->owned($userId);
        $balance = $account ? (string) DB::table('care360_wallet_entries')
            ->where('account_id', $account->id)->sum('signed_minor') : '0';
        return ['currency' => 'NGN', 'balance_minor' => $balance];
    }

    public function history(int $userId, ?int $before = null): array
    {
        $this->guard($userId);
        if ($before !== null && $before <= 0) {
            $this->reject();
        }
        $account = $this->owned($userId);
        if (! $account) {
            return ['entries' => [], 'next_before' => null];
        }
        $rows = DB::table('care360_wallet_entries as e')
            ->join('care360_wallet_journals as j', 'j.id', '=', 'e.journal_id')
            ->where('e.account_id', $account->id)
            ->where('j.currency', 'NGN')
            ->when($before !== null, fn ($q) => $q->where('e.id', '<', $before))
            ->orderByDesc('e.id')->limit(51)
            ->get(['e.id', 'e.signed_minor', 'e.created_at', 'j.kind']);
        $more = $rows->count() > 50;
        $entries = $rows->take(50)->map(fn ($row) => [
            'id' => (int) $row->id, 'amount_minor' => (string) $row->signed_minor,
            'kind' => $row->kind, 'created_at' => $row->created_at,
        ])->values();
        return ['entries' => $entries->all(),
            'next_before' => $more ? $entries->last()['id'] : null];
    }

    private function owned(int $userId): ?object
    {
        return DB::table('care360_wallet_accounts')
            ->where('account_key', 'customer:NGN:'.$userId)
            ->where('user_id', $userId)->where('kind', 'customer')
            ->where('currency', 'NGN')->first();
    }

    private function guard(int $userId): void
    {
        if ($userId <= 0 || ! config('care360_wallet.enabled', false)) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['wallet' => 'Wallet unavailable.']);
    }
}
