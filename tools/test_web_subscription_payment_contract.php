<?php
/**
 * Offline source-only checks for legacy doctor web subscription payment paths.
 * Does not contact a gateway or inspect payments; PHP feature tests still needed.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'web' => 'app/Http/Controllers/Doctor/DoctorSubscriptionController.php',
    'admin' => 'app/Http/Controllers/SuperAdmin/SubscriptionController.php',
    'api' => 'app/Http/Controllers/DoctorApiController.php',
    'gateway' => 'app/Services/PaymentGatewayService.php',
    'reference' => 'app/Services/SubscriptionCheckoutReference.php',
    'blade' => 'resources/views/doctor/subscription/flutter.blade.php',
    'routes' => 'routes/web.php',
    'test' => 'tools/test_subscription_checkout_binding.php',
];
$code = [];
foreach ($paths as $label => $file) {
    $code[$label] = file_get_contents($root.'/'.$file);
    if (! is_string($code[$label])) {
        fwrite(STDERR, "FAIL: source absent: $label\n");
        exit(1);
    }
}
function segment(string $source, string $first, string $next): string
{
    $start = strpos($source, 'public function '.$first.'(');
    $stop = $start === false ? false : strpos($source, 'public function '.$next.'(', $start);
    if ($start === false || $stop === false) {
        throw new RuntimeException('Missing web subscription method anchor.');
    }
    return substr($source, $start, $stop-$start);
}
$purchase = segment($code['web'], 'purchase', 'subscription_flutter');
$callback = segment($code['web'], 'subscription_flutter_verify', 'stripePayment');
$checkout = segment($code['web'], 'subscription_flutter', 'subscription_flutter_verify');
$apiPurchase = segment($code['api'], 'apiPurchaseSubscription', 'apiPaystackInit');
$old = strpos($code['admin'], 'public function changePaymentStatus(');
if ($old === false) throw new RuntimeException('Missing deprecated payment status route target.');
$oldController = substr($code['admin'], $old);
$checks = [
    'Legacy GET confirmation does not mutate paid state' =>
        str_contains($oldController, "'PAYMENT_VERIFICATION_REQUIRED'")
        && str_contains($oldController, '], 410)')
        && ! str_contains($oldController, "->update(['payment_status' => 1])")
        && str_contains($code['routes'], "Route::get('subscription/changePaymentStatus/{id}'"),
    'Web subscription never activates unpaid purchase' =>
        str_contains($purchase, "\$data['status'] = \$paid ? 1 : 0"),
    'Web plan activation shares transaction with payment receipt claim' =>
        str_contains($purchase, 'DB::transaction(')
        && str_contains($purchase, "->claim(")
        && str_contains($purchase, "->lockForUpdate()")
        && str_contains($purchase, 'if ($paid)'),
    'Web Stripe charge stamps authenticated user ownership metadata' =>
        str_contains($code['web'], "'metadata' => ['user_id' => (string) auth()->id()]"),
    'API Stripe card token charge stamps auth ownership too' =>
        str_contains($code['gateway'], "'metadata' => ['user_id' => (string) \$userId]"),
    'Flutterwave hosted checkout uses server-bound reference instead of plan ID' =>
        str_contains($code['blade'], 'name="tx_ref" value="{{ $txRef }}"')
        && ! str_contains($code['blade'], 'value="{{ $subscription->subscription_id }}"'),
    'Subscription reference uses keyed, identity-and-price HMAC' =>
        str_contains($code['reference'], "hash_hmac('sha256', \$binding, \$secret)")
        && str_contains($code['reference'], "\$subscriptionId.'|'.\$userId.'|'.\$amountMinor"),
    'Web callback verifies exact gateway reference before activation' =>
        str_contains($callback, 'SubscriptionCheckoutReference::forSubscription(')
        && str_contains($callback, 'auth()->id(), null, $expectedRef')
        && str_contains($callback, "(int) (\$data['payment_status'] ?? 0) !== 1")
        && str_contains($callback, 'DB::transaction(')
        && str_contains($callback, "->claim("),
    'Gateway checks expected signed tx_ref and matching transaction id' =>
        str_contains($code['gateway'], 'hash_equals($expectedReference, $data[\'tx_ref\'])')
        && str_contains($code['gateway'], "(string) (\$data['id'] ?? '') !== \$transactionId"),
    'API and web plan activations acquire same doctor lock' =>
        str_contains($apiPurchase, 'Doctor::whereKey($doctor->id)->lockForUpdate()->firstOrFail()')
        && str_contains($purchase, 'Doctor::whereKey($doctor->id)->lockForUpdate()->firstOrFail()'),
    'Signed checkout reference has offline runtime tests' =>
        str_contains($code['test'], 'Different')
        || str_contains($code['test'], 'A different payer cannot reuse the reference'),
];
$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS: ' : 'FAIL: ').$name.PHP_EOL;
    $failed += $ok ? 0 : 1;
}
echo "NOTE: Source checks only, not Laravel or live payment acceptance.".PHP_EOL;
exit($failed === 0 ? 0 : 1);
