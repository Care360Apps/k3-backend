<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class WalletFundingService
{
    private function guard(): void
    {
        if (! config('care360_wallet.enabled', false) || ! config('care360_wallet.funding_enabled', false)
            || ! PaymentReceiptClaimService::enabled()
            || ! config('services.paystack.secret')) {
            $this->reject();
        }
    }

    public function initialize(int $userId, string $email, int $amount): array
    {
        $this->guard();
        if ($userId <= 0 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || $amount < 10000 || $amount > 10000000) {
            $this->reject();
        }
        $reference = 'c360-wallet-'.bin2hex(random_bytes(24));
        DB::table('care360_wallet_funding')->insert([
            'reference' => $reference, 'user_id' => $userId,
            'amount_minor' => $amount, 'currency' => 'NGN', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = Http::withToken(config('services.paystack.secret'))->timeout(20)
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $email, 'amount' => $amount, 'currency' => 'NGN',
                'reference' => $reference,
                'metadata' => ['user_id' => (string) $userId, 'purpose' => 'wallet_funding'],
            ])->throw()->json();
        $url = data_get($result, 'data.authorization_url');
        if (($result['status'] ?? false) !== true || ! is_string($url)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'checkout.paystack.com'
            || parse_url($url, PHP_URL_USER) !== null
            || data_get($result, 'data.reference') !== $reference) {
            $this->reject();
        }
        return ['reference' => $reference, 'authorization_url' => $url];
    }

    public function confirm(int $userId, string $reference): int
    {
        $this->guard();
        if (! preg_match('/^c360-wallet-[a-f0-9]{48}$/D', $reference)) {
            $this->reject();
        }
        $intent = DB::table('care360_wallet_funding')->where('reference', $reference)
            ->where('user_id', $userId)->first();
        if (! $intent) {
            $this->reject();
        }
        if ($intent->status === 'credited' && $intent->journal_id) {
            return (int) $intent->journal_id;
        }
        $result = Http::withToken(config('services.paystack.secret'))->timeout(20)
            ->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference))->throw()->json();
        $data = $result['data'] ?? [];
        if (($result['status'] ?? false) !== true || ($data['status'] ?? null) !== 'success'
            || ($data['reference'] ?? null) !== $reference || ($data['currency'] ?? null) !== 'NGN'
            || ($data['amount'] ?? null) !== (int) $intent->amount_minor
            || (string) data_get($data, 'metadata.user_id') !== (string) $userId
            || data_get($data, 'metadata.purpose') !== 'wallet_funding') {
            $this->reject();
        }
        return DB::transaction(function () use ($userId, $reference, $intent) {
            $locked = DB::table('care360_wallet_funding')->where('id', $intent->id)
                ->where('user_id', $userId)->lockForUpdate()->first();
            if (! $locked) {
                $this->reject();
            }
            if ($locked->status === 'credited' && $locked->journal_id) {
                return (int) $locked->journal_id;
            }
            if ($locked->status !== 'pending' || (int) $locked->amount_minor !== (int) $intent->amount_minor) {
                $this->reject();
            }
            $customer = app(WalletAccountService::class)->account($userId);
            $system = DB::table('care360_wallet_accounts')->where('account_key', 'system:NGN:paystack')
                ->where('kind', 'system')->whereNull('user_id')->where('currency', 'NGN')->first();
            if (! $system) {
                $this->reject();
            }
            app(PaymentReceiptClaimService::class)->claim([
                'payment_status' => 1, 'payment_type' => 'PAYSTACK', 'payment_token' => $reference,
            ], 'wallet_funding', (int) $locked->id, $userId);
            $journal = app(WalletPostingService::class)->post('fund:'.$reference, 'funding', 'NGN', [
                $customer => (int) $locked->amount_minor, (int) $system->id => -(int) $locked->amount_minor,
            ]);
            DB::table('care360_wallet_funding')->where('id', $locked->id)->update([
                'status' => 'credited', 'journal_id' => $journal, 'updated_at' => now(),
            ]);
            return $journal;
        }, 3);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['wallet' => 'Wallet funding unavailable or unverified.']);
    }
}
