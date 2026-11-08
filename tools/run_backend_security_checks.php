<?php
/**
 * Portable, read-only Care360 Laravel security source checks.
 *
 * Usage: php tools/run_backend_security_checks.php
 * Never accesses a database, contacts a gateway or runs production migrations.
 * The separate PHPUnit/Laravel feature suite requires a verified synthetic
 * staging environment and is deliberately not launched from this script.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = dirname(__DIR__);
$php = PHP_BINARY;
$folders = ['app', 'config', 'database', 'routes', 'tests', 'tools'];
$sourceFiles = [];
foreach ($folders as $folder) {
    $directory = $root.DIRECTORY_SEPARATOR.$folder;
    if (! is_dir($directory)) {
        fwrite(STDERR, "Missing source directory: $folder\n");
        exit(1);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $sourceFiles[] = $entry->getPathname();
        }
    }
}
sort($sourceFiles);
if ($sourceFiles === []) {
    fwrite(STDERR, "No PHP source to verify.\n"); exit(1);
}

$tests = [
    'test_profile_image_upload.php',
    'test_otp_verification.php',
    'test_payment_verification.php',
    'test_payment_completion_contract.php',
    'test_private_medical_download_contract.php',
    'test_private_prescription_write_contract.php',
    'test_private_prescription_urls_runtime.php',
    'test_security_preflight_contract.php',
    'test_medical_migration_contract.php',
    'test_backend_security_continuation_contract.php',
    'test_approved_professional_guard_contract.php',
    'test_professional_firebase_gate_contract.php',
    'test_atomic_payment_receipts_contract.php',
    'test_clinical_suspension_and_private_provenance_contract.php',
    'test_subscription_checkout_binding.php',
    'test_web_subscription_payment_contract.php',
    'test_stripe_charge_binding_contract.php',
    'test_subscription_activation_contract.php',
    'test_pharmacy_commission.php',
    'test_pharmacy_stock_guard.php',
    'test_doctor_notes_contract.php',
    'test_agora_session_policy.php',
    'test_workforce_pilot_contract.php',
];
function runPhp(string $php, string $file, bool $lint = false): bool
{
    $command = escapeshellarg($php).' '.($lint ? '-l ' : '').escapeshellarg($file);
    $lines = [];
    $status = 1;
    exec($command.' 2>&1', $lines, $status);
    if ($status !== 0) {
        fwrite(STDERR, "FAIL: ".basename($file)."\n".implode("\n", $lines)."\n");
        return false;
    }
    return true;
}
$lintPass = 0;
foreach ($sourceFiles as $path) {
    if (runPhp($php, $path, true)) {
        $lintPass++;
    }
}
printf("PHP syntax: %d/%d passed.\n", $lintPass, count($sourceFiles));
$pass = 0;
foreach ($tests as $test) {
    $path = $root.'/tools/'.$test;
    if (! is_file($path)) {
        fwrite(STDERR, "FAIL: missing offline test: $test\n");
        continue;
    }
    if (runPhp($php, $path)) {
        echo "PASS: $test\n";
        $pass++;
    }
}
printf("Offline synthetic/source checks: %d/%d passed.\n", $pass, count($tests));
echo "NOT TESTED HERE: Laravel/PHPUnit endpoints, staging DB, real payments, public medical URLs and live infrastructure.\n";
exit($lintPass === count($sourceFiles) && $pass === count($tests) ? 0 : 1);
