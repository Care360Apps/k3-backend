<?php
/**
 * Offline structural safety assertions for clinical settlement, booking approval
 * and payment replay. No database, provider network or clinical records used.
 */
$root = dirname(__DIR__).'/app';
$patient = file_get_contents($root.'/Http/Controllers/UserApiController.php');
$professional = file_get_contents($root.'/Http/Controllers/DoctorApiController.php');
$gateway = file_get_contents($root.'/Services/PaymentGatewayService.php');
$harness = file_get_contents(dirname(__DIR__).'/tools/test_payment_verification.php');
if (!is_string($patient) || !is_string($professional) || !is_string($gateway) || !is_string($harness)) {
    fwrite(STDERR, "FAIL: missing source.\n"); exit(1);
}
function methodPart(string $src, string $from, string $to): string {
    $i = strpos($src, 'public function '.$from.'(');
    $j = $i === false ? false : strpos($src, 'public function '.$to.'(', $i);
    if ($i === false || $j === false) throw new RuntimeException('Missing method anchor: '.$from);
    return substr($src, $i, $j - $i);
}
$patientComplete = methodPart($patient,'apiCompleteAppointment','apiCancelAppointment');
$doctorComplete = methodPart($professional,'apiStatusChange','apiAppointmentHistory');
$booking = methodPart($patient,'apiBooking','apiAppointments');
$checks = [
    'Patient completion uses transaction and row lock' =>
        str_contains($patientComplete, 'DB::transaction(')
        && str_contains($patientComplete, '->lockForUpdate()'),
    'Professional completion uses transaction and row lock' =>
        str_contains($doctorComplete, 'DB::transaction(')
        && str_contains($doctorComplete, '->lockForUpdate()'),
    'Both completion routes create no duplicate settlement rows' =>
        str_contains($patientComplete, 'Settle::firstOrCreate(')
        && str_contains($doctorComplete, 'Settle::firstOrCreate('),
    'Both payment flags require the pre-existing verified gateway status' =>
        str_contains($patientComplete, '&& (int) $appointment->payment_status === 1')
        && str_contains($doctorComplete, '&& (int) $item->payment_status === 1'),
    'Clinical appointments require approved and active professionals' =>
        str_contains($booking, '(int) $doctor->is_approved !== 1')
        && str_contains($booking, '(int) $doctor->status !== 1'),
    'Gateway guards replays across all four Care360 product tables' =>
        str_contains($gateway, 'usedAcrossCare360Services($verifiedToken)')
        && str_contains($gateway, 'Appointment::class,')
        && str_contains($gateway, 'PurchaseMedicine::class,')
        && str_contains($gateway, 'Report::class,')
        && str_contains($gateway, 'DoctorSubscription::class,'),
    'Synthetic payment harness probes all four cross-product tables' =>
        str_contains($harness, 'medicine receipt cannot pay second order')
        && str_contains($harness, 'lab receipt cannot be replayed')
        && str_contains($harness, 'subscription receipt cannot be replayed')
        && str_contains($harness, 'payment reference on appointment cannot be reused'),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS: ' : 'FAIL: ').$label.PHP_EOL;
    $failed += $ok ? 0 : 1;
}
echo 'NOTE: This verifies source structure only, not database transaction concurrency, real payments, or PHP runtime API acceptance.'.PHP_EOL;
exit($failed === 0 ? 0 : 1);
