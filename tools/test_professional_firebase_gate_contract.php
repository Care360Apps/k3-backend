<?php

/**
 * Read-only source gate for clinical approval, suspension and Firebase token issuance.
 * These checks do NOT validate Firebase rules or revoke tokens already issued.
 * Run feature tests with synthetic SQLite separately.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$doctor = file_get_contents($root.'/app/Http/Controllers/DoctorApiController.php');
$provider = file_get_contents($root.'/app/Http/Controllers/ProviderApiController.php');
$middleware = file_get_contents($root.'/app/Http/Middleware/EnsureApprovedProfessional.php');
$medical = file_get_contents($root.'/app/Http/Controllers/PatientMedicalFileController.php');
$fixture = file_get_contents($root.'/tests/Feature/ApprovedProfessionalAccessTest.php');
if (! is_string($doctor) || ! is_string($provider) || ! is_string($middleware)
    || ! is_string($medical) || ! is_string($fixture)) {
    fwrite(STDERR, "FAIL: required clinical source missing.\n");
    exit(1);
}

function section(string $source, string $begin, string $end): string
{
    $start = strpos($source, 'public function '.$begin.'(');
    $finish = $start === false ? false : strpos($source, 'public function '.$end.'(', $start);
    if ($start === false || $finish === false) {
        throw new RuntimeException('Missing method anchor.');
    }
    return substr($source, $start, $finish - $start);
}

$doctorLogin = section($doctor, 'apiDoctorLogin', 'apiLogout');
$providerRegister = section($provider, 'apiProviderRegister', 'apiProviderLogin');
$loginStart = strpos($provider, 'public function apiProviderLogin(');
if ($loginStart === false) {
    throw new RuntimeException('Missing provider login method.');
}
$providerLogin = substr($provider, $loginStart);
$checks = [
    'New provider remains explicitly unapproved' =>
        str_contains($providerRegister, "\$data['is_approved'] = 0;"),
    'New provider receives no Firebase chat token before approval' =>
        str_contains($providerRegister, "\$userProvider['firebase_custom_token'] = null;")
        && str_contains($providerRegister, "\$userProvider['approval_status'] = 'pending';"),
    'Returning provider is checked for account suspension and exact role' =>
        str_contains($providerLogin, "\$doctor->user_role !== 'provider'")
        && str_contains($providerLogin, "(int) \$user->status !== 1"),
    'Provider Firebase token is gated on current approval' =>
        str_contains($providerLogin, "(int) \$doctor->is_approved === 1")
        && str_contains($providerLogin, "->providerToken(\$user->id)"),
    'Doctor login checks missing profile and account suspension before token issuance' =>
        str_contains($doctorLogin, "if (! \$doctor ||")
        && str_contains($doctorLogin, "(int) \$userDoctor->status !== 1")
        && strpos($doctorLogin, "(int) \$userDoctor->status !== 1")
            < strpos($doctorLogin, "->doctorToken(\$userDoctor->id)"),
    'Doctor Firebase token is gated on current approval' =>
        str_contains($doctorLogin, "(int) \$doctor->is_approved === 1")
        && str_contains($doctorLogin, "->doctorToken(\$userDoctor->id)"),
    'Clinical Passport tokens cannot bypass suspended account guard' =>
        str_contains($middleware, "(int) \$user->status !== 1")
        && str_contains($middleware, "->where('is_approved', 1)"),
    'Suspended doctor cannot use protected medical download' =>
        str_contains($medical, "(int) \$user->status === 1")
        && str_contains($medical, "\$user->hasRole('doctor')"),
    'Synthetic feature suite covers suspension after authentication' =>
        str_contains($fixture, "test_suspended_professional_user_is_rejected_even_with_existing_auth")
        && str_contains($fixture, "where('id', 101)->update(['status' => 0])"),
];
$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS: ' : 'FAIL: ').$label.PHP_EOL;
    $failed += $passed ? 0 : 1;
}
echo "NOTE: Structural checks only; no Laravel integration or Firebase rules reviewed.\n";
exit($failed === 0 ? 0 : 1);
