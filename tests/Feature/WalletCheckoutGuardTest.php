<?php

namespace Tests\Feature;

use App\Services\WalletCheckoutService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WalletCheckoutGuardTest extends TestCase
{
    public function test_checkout_is_independently_disabled(): void
    {
        config(['care360_wallet.enabled' => true, 'care360_wallet.checkout_enabled' => false]);
        $this->expectException(ValidationException::class);
        app(WalletCheckoutService::class)->debitAppointment(1, 1, 10000);
    }

    public function test_checkout_requires_outer_transaction(): void
    {
        config([
            'care360_wallet.enabled' => true,
            'care360_wallet.checkout_enabled' => true,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        $this->expectException(ValidationException::class);
        app(WalletCheckoutService::class)->debitAppointment(1, 1, 10000);
    }
}
