<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WalletCheckoutService
{
    // Internal only: call within the authorized appointment creation transaction.
    public function debitAppointment(int $userId, int $appointmentId, int $amountMinor): int
    {
        if (! config('care360_wallet.enabled', false) || ! config('care360_wallet.checkout_enabled', false)
            || DB::transactionLevel() < 1 || $userId <= 0 || $appointmentId <= 0
            || $amountMinor <= 0 || $amountMinor > 100000000000) {
            $this->reject();
        }
        $order = DB::table('appointment')->where('id', $appointmentId)
            ->where('user_id', $userId)->lockForUpdate()->first();
        if (! $order || strtoupper((string) $order->payment_type) !== 'WALLET'
            || (int) $order->payment_status !== 1
            || ! is_numeric($order->amount)
            || (int) round((float) $order->amount * 100) !== $amountMinor) {
            $this->reject();
        }
        $customer = app(WalletAccountService::class)->account($userId);
        $system = DB::table('care360_wallet_accounts')
            ->where('account_key', 'system:NGN:checkout')->where('kind', 'system')
            ->whereNull('user_id')->where('currency', 'NGN')->first();
        if (! $system) {
            $this->reject();
        }
        return app(WalletPostingService::class)->post(
            'appointment:'.$appointmentId, 'checkout', 'NGN',
            [$customer => -$amountMinor, (int) $system->id => $amountMinor]
        );
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['wallet' => 'Wallet checkout unavailable or invalid.']);
    }
}
