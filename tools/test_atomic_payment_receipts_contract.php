<?php

/**
 * Static source gate for atomic receipt claims.
 *
 * This is NOT a concurrency test; MySQL staging transactions and actual
 * payment-provider sandbox authorization must pass separately.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'service' => 'app/Services/PaymentReceiptClaimService.php',
    'config' => 'config/payment_security.php',
    'migration' => 'database/migrations/2026_11_08_070000_create_payment_receipt_claims.php',
    'patient' => 'app/Http/Controllers/UserApiController.php',
    'professional' => 'app/Http/Controllers/DoctorApiController.php',
    'feature' => 'tests/Feature/AtomicPaymentReceiptClaimTest.php',
];
$code = [];
foreach ($sources as $label => $file) {
    $code[$label] = file_get_contents($root.'/'.$file);
    if (! is_string($code[$label])) {
        fwrite(STDERR, "FAIL: missing receipt source: $label\n"); exit(1);
    }
}
function block(string $src, string $start, string $end): string
{
    $i = strpos($src, 'public function '.$start.'(');
    $j = $i === false ? false : strpos($src, 'public function '.$end.'(', $i);
    if ($i === false || $j === false) throw new RuntimeException('Missing receipt method anchor.');
    return substr($src, $i, $j-$i);
}
$booking = block($code['patient'], 'apiBooking', 'apiAppointments');
$lab = block($code['patient'], 'apiLabBook', 'priceLabTests');
$medicine = block($code['patient'], 'apiBookMedicine', 'apiMedicines');
$subscription = block($code['professional'], 'apiPurchaseSubscription', 'apiPaystackInit');
$checks = [
    'Receipt gate explicitly disabled by default' =>
        str_contains($code['config'], "env('K3_ATOMIC_PAYMENT_RECEIPTS', false)"),
    'New claims require previously verified payment status' =>
        str_contains($code['service'], "payment_status")
        && str_contains($code['service'], "!== 1"),
    'Unique receipt index across product types' =>
        str_contains($code['migration'], "->char('receipt_sha256', 64)->unique()"),
    'Raw payment reference is hashed before persistence' =>
        str_contains($code['service'], "hash('sha256', \$reference)")
        && ! str_contains($code['service'], "'payment_reference' => \$reference"),
    'Failed or duplicate database insert returns a safe validation error' =>
        str_contains($code['service'], 'catch (QueryException $exception)')
        && str_contains($code['service'], "ValidationException::withMessages"),
    'Database migration refuses to discard nonempty audit ledger' =>
        str_contains($code['migration'], "DB::table('payment_receipt_claims')->exists()"),
    'Appointment creation and receipt claim share transaction' =>
        str_contains($booking, 'DB::transaction(function () use ($data, $offer)')
        && str_contains($booking, "->claim(\n                \$data, 'appointment'"),
    'Lab creation and receipt claim share transaction' =>
        str_contains($lab, 'DB::transaction(function () use ($data, $lab)')
        && str_contains($lab, "->claim(\n                \$data, 'lab'"),
    'Medicine stock and receipt claim share existing transaction' =>
        str_contains($medicine, 'DB::transaction(function () use ($data, $order)')
        && str_contains($medicine, "->claim(\n                \$data, 'medicine'"),
    'Subscription activation and receipt claim share transaction' =>
        str_contains($subscription, 'DB::transaction(function () use ($doctor, $data, $paid)')
        && str_contains($subscription, "->claim(\n                \$data, 'subscription'"),
    'Synthetic tests cover duplicate, failure, missing schema and opt-out' =>
        str_contains($code['feature'], 'test_one_gateway_receipt_cannot_pay_multiple_product_types')
        && str_contains($code['feature'], 'test_claim_is_rolled_back_with_failed_order_transaction')
        && str_contains($code['feature'], 'test_paid_receipt_fails_closed_if_ledger_table_is_missing')
        && str_contains($code['feature'], 'test_disabled_flag_preserves_legacy_draft_behavior'),
];
$bad = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS: ' : 'FAIL: ').$name.PHP_EOL;
    $bad += $ok ? 0 : 1;
}
echo "NOTE: Static checks only; parallel MySQL transactions and provider receipts are unverified.\n";
exit($bad ? 1 : 0);
