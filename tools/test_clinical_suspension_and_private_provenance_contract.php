<?php
/**
 * Offline source contract for suspended clinical accounts and private file
 * provenance. This is not a substitute for Laravel feature tests.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'doctor' => 'app/Http/Controllers/DoctorApiController.php',
    'patient' => 'app/Http/Controllers/UserApiController.php',
    'download' => 'app/Http/Controllers/PatientMedicalFileController.php',
    'storage' => 'app/Services/PrivatePrescriptionStorage.php',
    'features' => 'tests/Feature/PrivatePrescriptionAccessTest.php',
];
$sources = [];
foreach ($files as $name => $path) {
    $data = file_get_contents($root.'/'.$path);
    if (! is_string($data)) {
        fwrite(STDERR, "FAIL: clinical source not found: $name\n");
        exit(1);
    }
    $sources[$name] = $data;
}
function clinicalMethod(string $source, string $from, string $to): string
{
    $start = strpos($source, 'public function '.$from.'(');
    $end = $start === false ? false : strpos($source, 'public function '.$to.'(', $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('Missing clinical function anchor.');
    }
    return substr($source, $start, $end-$start);
}
$view = clinicalMethod($sources['doctor'], 'apiSingleAppointment', 'apiAddPrescription');
$booking = clinicalMethod($sources['patient'], 'apiBooking', 'apiAppointments');
$download = $sources['download'];
$checks = [
    'Suspended clinician denied shared appointment details even with existing token' =>
        str_contains($view, "(int) (auth()->user()->status ?? 0) !== 1")
        && str_contains($view, 'Appointment not found'),
    'Unavailable or suspended professional cannot receive new bookings' =>
        str_contains($booking, "User::whereKey(\$doctor->user_id)->where('status', 1)->exists()"),
    'Private document controller recognizes private provenance only' =>
        str_contains($download, 'PrivatePrescriptionStorage::isPrivateFilename($name)')
        && ! str_contains($download, "preg_match('/\\\\A[a-zA-Z0-9_-]{16,80}"),
    'Private writer filenames are generated from random bytes' =>
        str_contains($sources['storage'], "'k3priv_'.bin2hex(random_bytes(20)).'.pdf'"),
    'Non-private lookalike PDFs are explicitly rejected in synthetic tests' =>
        str_contains($sources['features'], 'test_arbitrary_pdf_inside_private_directory_is_not_downloadable')
        && str_contains($sources['features'], "'external_'.bin2hex(random_bytes(20)).'.pdf'"),
    'Patient access remains available if assigned clinician is suspended' =>
        str_contains($sources['features'], 'test_suspended_doctor_cannot_download_but_patient_still_can'),
];
$failures = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS: ' : 'FAIL: ').$name.PHP_EOL;
    $failures += $ok ? 0 : 1;
}
echo "NOTE: Static checks only; database and API acceptance still blocked.\n";
exit($failures ? 1 : 0);
