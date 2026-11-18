<?php

namespace Tests\Feature;

use App\Services\PaymentReceiptClaimService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AtomicPaymentReceiptClaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing')) {
            $this->markTestSkipped('Payment receipt ledger tests require synthetic testing.');
        }
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        config()->set('payment_security.atomic_receipt_claims', true);

        Schema::create('payment_receipt_claims', function (Blueprint $table) {
            $table->id();
            $table->char('receipt_sha256', 64)->unique();
            $table->string('gateway', 24);
            $table->unsignedBigInteger('user_id');
            $table->string('product_type', 32);
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function paid(string $token): array
    {
        return [
            'payment_status' => 1,
            'payment_type' => 'PAYSTACK',
            'payment_token' => $token,
        ];
    }

    public function test_one_gateway_receipt_cannot_pay_multiple_product_types(): void
    {
        $service = new PaymentReceiptClaimService();
        $service->claim($this->paid('synthetic-receipt-01'), 'appointment', 301, 101);
        $this->assertSame(1, DB::table('payment_receipt_claims')->count());
        $this->assertSame(
            hash('sha256', 'synthetic-receipt-01'),
            DB::table('payment_receipt_claims')->value('receipt_sha256')
        );
        $this->assertFalse(
            str_contains(json_encode(DB::table('payment_receipt_claims')->first()), 'synthetic-receipt-01')
        );

        try {
            $service->claim($this->paid('synthetic-receipt-01'), 'medicine', 401, 102);
            $this->fail('Duplicate receipt should be rejected.');
        } catch (ValidationException $expected) {
            $this->assertSame(1, DB::table('payment_receipt_claims')->count());
        }
        $service->claim($this->paid('synthetic-receipt-02'), 'lab', 501, 103);
        $this->assertSame(2, DB::table('payment_receipt_claims')->count());
    }

    public function test_unpaid_checkout_never_creates_receipt_claim(): void
    {
        (new PaymentReceiptClaimService())->claim([
            'payment_status' => 0, 'payment_type' => 'COD', 'payment_token' => null,
        ], 'appointment', 301, 101);
        $this->assertSame(0, DB::table('payment_receipt_claims')->count());
    }

    public function test_claim_is_rolled_back_with_failed_order_transaction(): void
    {
        try {
            DB::transaction(function () {
                (new PaymentReceiptClaimService())->claim(
                    $this->paid('synthetic-rollback-01'), 'subscription', 301, 101
                );
                throw new \RuntimeException('Synthetic order insert failed.');
            });
            $this->fail('Synthetic failure must abort transaction.');
        } catch (\RuntimeException $expected) {
            $this->assertSame(0, DB::table('payment_receipt_claims')->count());
        }
    }

    public function test_paid_receipt_fails_closed_if_ledger_table_is_missing(): void
    {
        Schema::drop('payment_receipt_claims');
        $this->expectException(ValidationException::class);
        (new PaymentReceiptClaimService())->claim(
            $this->paid('synthetic-no-table'), 'medicine', 401, 101
        );
    }

    public function test_disabled_flag_preserves_legacy_draft_behavior(): void
    {
        config()->set('payment_security.atomic_receipt_claims', false);
        (new PaymentReceiptClaimService())->claim(
            $this->paid('synthetic-opt-out'), 'lab', 501, 101
        );
        $this->assertSame(0, DB::table('payment_receipt_claims')->count());
    }

    public function test_invalid_paid_payload_is_never_claimed(): void
    {
        $this->expectException(ValidationException::class);
        (new PaymentReceiptClaimService())->claim([
            'payment_status' => 1, 'payment_type' => 'COD',
            'payment_token' => '',
        ], 'appointment', 301, 101);
    }
}
