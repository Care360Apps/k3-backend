<?php

/**
 * Regression source guard for approved clinician actions and paid subscriptions.
 * Does not connect to any account or payment system.
 */
$source = file_get_contents(dirname(__DIR__).'/app/Http/Controllers/DoctorApiController.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: missing doctor controller\n");
    exit(1);
}
$start = strpos($source, 'function apiPurchaseSubscription(');
$end = strpos($source, 'function apiPaystackInit(', $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "FAIL: subscription method unavailable\n");
    exit(1);
}
$method = substr($source, $start, $end - $start);
$checks = [
    'Subscription status is based exclusively on verified payment' =>
        str_contains($method, "\$data['status'] = \$paid ? 1 : 0"),
    'Unpaid purchase cannot cancel previous paid subscription' =>
        str_contains($method, 'if ($paid) {')
        && str_contains($method, 'DoctorSubscription::where('),
    'Paid replacement and creation are transactional' =>
        str_contains($method, 'Facades\\DB::transaction('),
    'Doctor subscription flag not reset on unpaid purchase attempt' =>
        ! str_contains($method, "\$doctor->subscription_status = 0"),
    'Status mutation requires approved, active professional' =>
        str_contains($source, "Professional approval required")
        && str_contains($source, "->where('is_approved', 1)->where('status', 1)->first()"),
    'Agora and notification professional activity checked' =>
        str_contains($source, "->where('is_approved', 1)->where('status', 1)->firstOrFail()")
        && str_contains($source, "->where('is_approved', 1)->where('status', 1)->first() : null;"),
];
$failed = 0;
foreach ($checks as $label => $pass) {
    echo ($pass ? 'PASS: ' : 'FAIL: ').$label.PHP_EOL;
    $failed += $pass ? 0 : 1;
}
exit($failed === 0 ? 0 : 1);
