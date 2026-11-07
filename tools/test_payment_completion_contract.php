<?php
// Static offline safety contract: a care-status transition must never forge
// verified payment. Actual Laravel/DB integration tests are still required.
$patient = file_get_contents(__DIR__.'/../app/Http/Controllers/UserApiController.php');
$doctor = file_get_contents(__DIR__.'/../app/Http/Controllers/DoctorApiController.php');
if ($patient === false || $doctor === false) {
    throw new RuntimeException('Cannot load appointment controllers');
}
function methodBody(string $source, string $start, string $next): string
{
    $begin = strpos($source, $start);
    if ($begin === false) throw new RuntimeException('Missing '.$start);
    $end = strpos($source, $next, $begin + strlen($start));
    if ($end === false) throw new RuntimeException('Missing next method after '.$start);
    return substr($source, $begin, $end - $begin);
}
foreach ([
    'Patient complete' => methodBody($patient, 'public function apiCompleteAppointment(', 'public function apiCancelAppointment('),
    'Doctor status' => methodBody($doctor, 'public function apiStatusChange(', 'public function apiAppointmentHistory('),
] as $label => $body) {
    if (str_contains($body, "'payment_status' => 1")) {
        throw new RuntimeException($label.' improperly changes payment state');
    }
    if (! str_contains($body, '&& (int) $appointment->payment_status === 1')) {
        throw new RuntimeException($label.' allows unverified gateway settlement');
    }
    if (! str_contains($body, "['appointment_status' =>")) {
        throw new RuntimeException($label.' no longer updates appointment status');
    }
    echo 'PASS: '.$label.' does not forge verified payments'.PHP_EOL;
}
