<?php
// Pure offline tests: no real medicine orders, payment requests or database.
require __DIR__.'/../app/Services/PharmacyCommission.php';

use App\Services\PharmacyCommission as Fee;

function verify($condition, $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
$default = Fee::split(10000.0, null);
verify($default['rate'] === 15.0, 'Default commission must be 15%');
verify($default['platform'] === 1500.0, '15% of NGN 10,000 must be NGN 1,500');
verify($default['pharmacy'] === 8500.0, 'Pharmacy receives NGN 8,500');
verify($default['platform'] + $default['pharmacy'] === 10000.0, 'Split must reconcile');
$custom = Fee::split(10000.0, 12.5);
verify($custom['platform'] === 1250.0 && $custom['pharmacy'] === 8750.0, 'Custom commission failed');
$none = Fee::split(99.99, 0);
verify($none['platform'] === 0.0 && $none['pharmacy'] === 99.99, 'Zero rate failed');
$all = Fee::split(100.0, 100);
verify($all['platform'] === 100.0 && $all['pharmacy'] === 0.0, '100% rate failed');
$rounding = Fee::split(33.33, 15);
verify($rounding['platform'] + $rounding['pharmacy'] === 33.33, 'Rounding must reconcile');
foreach ([-1, 101, 'abc'] as $invalid) {
    try {
        Fee::rate($invalid);
        throw new RuntimeException('Invalid rate was accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
foreach (['app/Http/Controllers/UserApiController.php',
          'app/Http/Controllers/Website/WebsiteController.php'] as $relative) {
    $source = file_get_contents(__DIR__.'/../'.$relative);
    verify(str_contains($source, "PharmacyCommission::split((float) \$order['items_total']"),
        $relative.' must split the medicine subtotal, not delivery fees');
}
$service = file_get_contents(__DIR__.'/../app/Services/MedicineImportService.php');
verify(str_contains($service, 'if ($previewOnly) {'), 'Bulk CSV needs a no-write preview');
verify(str_contains($service, 'return DB::transaction('), 'Bulk CSV import must remain atomic');
echo "PASS pharmacy 15% default, overrides, settlement reconciliation, API and web parity, safe import preview\n";
