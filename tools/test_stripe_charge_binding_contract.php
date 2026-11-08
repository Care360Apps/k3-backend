<?php

/**
 * Source-only financial safety regression. Never contacts payment gateways,
 * production credentials, accounts, financial or patient records.
 */
$gateway = file_get_contents(dirname(__DIR__).'/app/Services/PaymentGatewayService.php');
$controller = file_get_contents(dirname(__DIR__).'/app/Http/Controllers/PaymentApiController.php');
if ($gateway === false || $controller === false) {
    fwrite(STDERR, "FAIL: missing gateway source\n");
    exit(1);
}
$checks = [
    'Stripe charge must be a completed unrefunded payment' =>
        str_contains($gateway, "\$charge->status === 'succeeded'")
        && str_contains($gateway, "! \$charge->refunded"),
    'Externally supplied Stripe charge must match authenticated user' =>
        str_contains($gateway, "(string) (\$charge->metadata['user_id'] ?? '') === (string) \$userId"),
    'Stripe intent must match authenticated user and currency' =>
        str_contains($gateway, "(string) (\$intent->metadata['user_id'] ?? '') === (string) \$userId")
        && str_contains($gateway, '\$this->sameCurrency('),
    'Raw Stripe exception messages cannot be logged' =>
        ! str_contains($controller, "\$e->getMessage()")
        && str_contains($controller, "Stripe payment intent creation failed."),
    'Payment returns status only after gateway checks' =>
        str_contains($gateway, "\$data['payment_status'] = 0")
        && str_contains($gateway, "\$data['payment_status'] = 1"),
];
$failed = 0;
foreach ($checks as $name => $passes) {
    echo ($passes ? 'PASS: ' : 'FAIL: ').$name.PHP_EOL;
    $failed += $passes ? 0 : 1;
}
echo "NOTE: Cross-product receipt replay, provider-owner binding, refund reconciliation and production payment QA remain unverified.\n";
exit($failed === 0 ? 0 : 1);
