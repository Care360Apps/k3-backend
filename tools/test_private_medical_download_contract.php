<?php
// Static privacy contract; use isolated Laravel integration tests for actual
// authenticated streams. No patient records, database, production access.
$routes = file_get_contents(__DIR__.'/../routes/api.php');
$controller = file_get_contents(__DIR__.'/../app/Http/Controllers/PatientMedicalFileController.php');
if ($routes === false || $controller === false) {
    throw new RuntimeException('Missing medical-file controller or route');
}
$authStart = strpos($routes, "Route::middleware('auth:api')->group(function () {", strpos($routes, "Route::get('prescription/") - 4000);
$routePos = strpos($routes, "Route::get('medical-files/appointments/{appointmentId}/prescription'");
if ($authStart === false || $routePos === false || $routePos < $authStart) {
    throw new RuntimeException('Medical-file route must be behind auth:api');
}
foreach ([
    'where(\'user_id\', $appointment->user_id)',
    'str_starts_with($full, $base.DIRECTORY_SEPARATOR)',
    "Cache-Control' => 'private, no-store",
    'whereKey($appointment->doctor_id)',
    "preg_match(",
    "storage_path('app/private/medical/prescriptions')",
] as $requirement) {
    if (! str_contains($controller, $requirement)) {
        throw new RuntimeException('Medical-file ownership or private storage guard missing');
    }
}
if (str_contains($controller, "public_path(") ||
    str_contains($controller, "url('prescription/upload") ||
    str_contains($controller, "file_get_contents(")) {
    throw new RuntimeException('Medical-file endpoint cannot read or serve public files');
}
echo 'PASS: private medical download source contract; staging auth tests still required.'.PHP_EOL;
