<?php
// Offline contract only. A MySQL concurrent transaction test is still required.
function verifyStock($ok, $msg) { if (!$ok) throw new RuntimeException($msg); }
$base = __DIR__.'/../app/';
$guard = file_get_contents($base.'Services/MedicineStockGuard.php');
verifyStock(str_contains($guard, "whereRaw('(total_stock - use_stock) >= ?', [\$quantity])"), 'Missing conditional atomic capacity check');
verifyStock(str_contains($guard, "->increment('use_stock', \$quantity)"), 'Missing atomic increment');
verifyStock(str_contains($guard, "if (\$count !== 1)"), 'Missing out-of-stock failure');
foreach (['UserApiController.php', 'Website/WebsiteController.php'] as $path) {
    $s = file_get_contents($base.'Http/Controllers/'.$path);
    verifyStock(str_contains($s, 'MedicineStockGuard::consume'), "Stock guard missing from $path");
    verifyStock(! str_contains($s, "->increment('use_stock',"), "Unsafe stock increment in $path");
}
echo "PASS atomic stock capacity source contract; payment reconciliation still pending.\n";
