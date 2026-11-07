<?php
// Static privacy contract; use isolated Laravel integration tests for actual
// authenticated streams. No patient records, database, production access.
$routes = file_get_contents(__DIR__.'/../routes/api.php');
$controller = file_get_contents(__DIR__.'/../app/Http/Controllers/PatientMedicalFileController.php');
if ($routes === false || $controller === false) {
    throw new RuntimeException('Missing medical-file controller or route');
}
$routePos = strpos($routes, "Route::get('medical-files/appointments/{appointmentId}/prescription'");
if ($routePos === false) {
    throw new RuntimeException('Private medical-file route missing');
}
$authStart = strrpos(substr($routes, 0, $routePos), "Route::middleware('auth:api')->group(function () {");
$groupEnd = $authStart === false ? false : strpos($routes, "\n});", $authStart);
if ($authStart === false || ($groupEnd !== false && $groupEnd < $routePos)) {
    throw new RuntimeException('Medical-file route must be within an auth:api group');
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
