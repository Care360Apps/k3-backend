<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Called INSIDE the same transaction as the order's database insert.
 *
 * The unique receipt_sha256 index is what blocks concurrent duplicate claims.
 * This complements, but does not replace, gateway ownership verification,
 * refunds/reconciliation, pre-existing receipt migration or idempotent charges.
 */
final class PaymentReceiptClaimService
{
    public static function enabled(): bool
    {
        return config('payment_security.atomic_receipt_claims', false) === true;
    }

    public function claim(array $verified, string $productType, int $productId, int $userId): void
    {
        if ((int) ($verified['payment_status'] ?? 0) !== 1) {
            return;
        }
        // Do not silently accept a broken 'paid' status without a receipt.
        $reference = $verified['payment_token'] ?? null;
        $gateway = strtoupper(trim((string) ($verified['payment_type'] ?? '')));
        if (! is_string($reference) || $reference === '' || strlen($reference) > 512
            || ! in_array($gateway, ['STRIPE', 'PAYPAL', 'PAYSTACK', 'FLUTTERWAVE', 'RAZORPAY'], true)
            || ! in_array($productType, ['appointment', 'lab', 'medicine', 'subscription'], true)
            || $productId <= 0 || $userId <= 0) {
            throw ValidationException::withMessages([
                'payment_token' => 'Payment receipt could not be verified.',
            ]);
        }
        if (! self::enabled()) {
            // Legacy sequential replay checks still apply in PaymentGatewayService;
            // the atomic gate remains OFF until a full staging rehearsal.
            return;
        }

        try {
            DB::table('payment_receipt_claims')->insert([
                'receipt_sha256' => hash('sha256', $reference),
                'gateway' => $gateway,
                'user_id' => $userId,
                'product_type' => $productType,
                'product_id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // Missing migration, unique collision or storage errors must fail
            // closed. Do not log the gateway token, SQL bindings or DB message.
            logger()->warning('Atomic payment receipt claim rejected or unavailable.');
            throw ValidationException::withMessages([
                'payment_token' => 'Payment receipt is unavailable or already claimed.',
            ]);
        }
    }
}
