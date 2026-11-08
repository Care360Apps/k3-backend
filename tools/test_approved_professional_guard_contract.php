<?php

/** Static coverage of clinical/financial route approval gate; source only. */
$root = dirname(__DIR__);
$routes = file_get_contents($root.'/routes/api.php');
$guard = file_get_contents($root.'/app/Http/Middleware/EnsureApprovedProfessional.php');
$test = file_get_contents($root.'/tests/Feature/ApprovedProfessionalAccessTest.php');
if (! is_string($routes) || ! is_string($guard) || ! is_string($test)) {
    fwrite(STDERR, "FAIL: missing source.\n"); exit(1);
}
$protected = [
    'doctor_appointment','doctor_notes','doctor_notes/{id}','add_prescription',
    'status_change','updatePatientVcall','appointment_history','payment',
    'finance_details','withdrawal_requests','payout_account','notification',
    'cancel_appointment','generateDoctorAgoraToken','send_message_to_patient_notification'
];
$failures = 0;
foreach ($protected as $route) {
    $matched = false;
    foreach (explode("\n", $routes) as $line) {
        if (str_contains($line, "Route::")
            && str_contains($line, "'".$route."'")
            && str_contains($line, 'EnsureApprovedProfessional::class')) {
            $matched = true;
            break;
        }
    }
    echo ($matched ? 'PASS: ' : 'FAIL: ').$route.' protected'.PHP_EOL;
    if (! $matched) $failures++;
}
$checks = [
    'Middleware requires currently approved and active profile' =>
        str_contains($guard, "->where('is_approved', 1)")
        && str_contains($guard, "->where('status', 1)"),
    'Middleware requires matching doctor/provider role' =>
        str_contains($guard, "hasAnyRole(['doctor', 'provider'])")
        && str_contains($guard, '$user->hasRole($professionalRole)'),
    'Pending registration and profile updates remain possible' =>
        ! preg_match("/Route::post\('update_doctor'.*EnsureApprovedProfessional::class/", $routes)
        && ! preg_match("/Route::get\('doctor_profile'.*EnsureApprovedProfessional::class/", $routes),
    'Synthetic role changes and revocation have feature tests' =>
        str_contains($test, 'test_revoked_unapproved_and_patient_roles_are_rejected')
        && str_contains($test, 'test_role_mismatch_fails_closed'),
];
foreach ($checks as $label=>$ok) {
    echo ($ok ? 'PASS: ' : 'FAIL: ').$label.PHP_EOL;
    if (! $ok) $failures++;
}
exit($failures === 0 ? 0 : 1);
