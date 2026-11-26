<?php

namespace Tests\Feature;

use App\Services\WalletFundingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WalletFundingGuardTest extends TestCase
{
    public function test_funding_disabled_independently_of_wallet_access(): void
    {
        config(['care360_wallet.enabled' => true, 'care360_wallet.funding_enabled' => false]);
        Http::fake();
        try {
            app(WalletFundingService::class)->initialize(1, 'test@example.com', 10000);
            $this->fail('Disabled funding accepted');
        } catch (ValidationException $e) {
            Http::assertNothingSent();
        }
    }

    public function test_invalid_amounts_rejected_before_gateway_call(): void
    {
        config([
            'care360_wallet.enabled' => true,
            'care360_wallet.funding_enabled' => true,
            'payment_security.atomic_receipt_claims' => true,
            'services.paystack.secret' => 'synthetic-test-key',
        ]);
        Http::fake();
        foreach ([0, -1, 9999, 10000001] as $amount) {
            try {
                app(WalletFundingService::class)->initialize(1, 'test@example.com', $amount);
                $this->fail('Invalid amount accepted');
            } catch (ValidationException $e) {
                Http::assertNothingSent();
            }
        }
    }
}
