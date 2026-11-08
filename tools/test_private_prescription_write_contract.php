<?php

/**
 * Offline privacy gates for the staged prescription writer.
 * Does not open clinical files or connect to Namecheap/staging databases.
 */
$root = dirname(__DIR__);
$files = [
    'config' => 'config/medical_files.php',
    'service' => 'app/Services/PrivatePrescriptionStorage.php',
    'doctor' => 'app/Http/Controllers/DoctorApiController.php',
    'patient' => 'app/Http/Controllers/UserApiController.php',
    'download' => 'app/Http/Controllers/PatientMedicalFileController.php',
    'routes' => 'routes/api.php',
];

foreach ($files as $key => $file) {
    $value = file_get_contents($root.DIRECTORY_SEPARATOR.$file);
    if ($value === false) {
        fwrite(STDERR, 'FAIL: missing '.$file.PHP_EOL);
        exit(1);
    }
    $code[$key] = $value;
}

$checks = [
    'Private prescription writes disabled by default' =>
        str_contains($code['config'], "env('K3_PRIVATE_PRESCRIPTION_WRITES', false)"),
    'Private writer uses storage outside public web root' =>
        str_contains($code['service'], "storage_path('app')")
        && str_contains($code['service'], "['private', 'medical', 'prescriptions']")
        && ! str_contains($code['service'], 'public_path('),
    'Accept only real size-bounded PDF bytes' =>
        str_contains($code['service'], "getMimeType()")
        && str_contains($code['service'], "10 * 1024 * 1024")
        && str_contains($code['service'], "'%PDF-'"),
    'Private filenames are cryptographically unpredictable' =>
        str_contains($code['service'], "bin2hex(random_bytes(20)).'.pdf'"),
    'Private directory rejects symlinks and file is owner-only' =>
        str_contains($code['service'], 'is_link($path)')
        && str_contains($code['service'], 'chmod($moved->getPathname(), 0600)'),
    'Doctor writes are gated; legacy route still available before cutover' =>
        str_contains($code['doctor'], 'PrivatePrescriptionStorage::enabled()')
        && str_contains($code['doctor'], '$privateStore->store($request->file(\'pdf\'))')
        && str_contains($code['doctor'], 'public_path().\'/prescription/upload\''),
    'Failed database write removes new private file' =>
        str_contains($code['doctor'], '$privateStore->discard($privateFilename)')
        && str_contains($code['doctor'], 'Prescription::create($pre)'),
    'Private writer never returns a public URL when enabled' =>
        str_contains($code['service'], "url('api/medical-files/appointments/'")
        && str_contains($code['service'], "return self::enabled()"),
    'Doctor and patient endpoints return mode-consistent URLs' =>
        str_contains($code['doctor'], 'PrivatePrescriptionStorage::appointmentUrl')
        && substr_count($code['patient'], 'PrivatePrescriptionStorage::appointmentUrl') >= 2,
    'Patient missing prescriptions fail safely' =>
        str_contains($code['patient'], "'Prescription not found'"),
    'Authenticated private download remains ownership restricted' =>
        str_contains($code['download'], '$isPatient || $isAssignedProfessional')
        && str_contains($code['download'], 'where(\'user_id\', $appointment->user_id)')
        && str_contains($code['routes'], "Route::middleware('auth:api')->group(function () {"),
];

$failures = 0;
foreach ($checks as $name => $passes) {
    if ($passes) {
        echo "PASS: $name".PHP_EOL;
    } else {
        fwrite(STDERR, "FAIL: $name".PHP_EOL);
        $failures++;
    }
}
if ($failures) {
    exit(1);
}
echo 'NOTE: Old public clinical files, report images, pharmacy PDFs, live migrations and authenticated API integration remain OPEN release blockers.'.PHP_EOL;
