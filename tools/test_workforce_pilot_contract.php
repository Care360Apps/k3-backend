<?php

/**
 * No database or real users needed. This is a static fail-closed contract
 * supplement to the Laravel integration tests below.
 * Execute with: php tools/test_workforce_pilot_contract.php
 */
$root = dirname(__DIR__);
$files = [
    'api' => 'routes/api.php',
    'controller' => 'app/Http/Controllers/WorkforcePilotController.php',
    'middleware' => 'app/Http/Middleware/AuthenticateWorkforce.php',
    'feature' => 'app/Http/Middleware/WorkforcePilotEnabled.php',
    'config' => 'config/workforce.php',
    'migration' => 'database/migrations/2026_11_07_230000_create_workforce_pilot_tables.php',
];
$code = [];
foreach ($files as $key => $file) {
    $value = file_get_contents($root.'/'.$file);
    if ($value === false) { fwrite(STDERR, "Missing $file\n"); exit(1); }
    $code[$key] = $value;
}
$checks = [
    'Pilot disabled by default' => str_contains($code['config'],
        "env('K3_WORKFORCE_PILOT_ENABLED', false)"),
    'Workforce not in clinical auth group' => str_contains($code['api'],
        "Route::prefix('workforce/v1')->middleware([WorkforcePilotEnabled::class])")
        && str_contains($code['api'], "AuthenticateWorkforce::class"),
    'No Passport or clinical bearer in guard' => !str_contains($code['middleware'], 'auth:api')
        && !str_contains($code['middleware'], 'Passport::')
        && str_contains($code['middleware'], 'hash(\'sha256\', $rawToken)'),
    'Tokens are hashed and time limited' => str_contains($code['controller'],
        'hash(\'sha256\', $raw)') && str_contains($code['controller'],
        "config('workforce.session_hours', 12)"),
    'Candidate cannot set verification fields' => str_contains($code['controller'],
        "'verification_status', 'verified'")
        && !str_contains($code['controller'], "['verification_status'] ="),
    'Employer must be approved before browsing' => str_contains($code['controller'],
        "verification_status !== 'verified'"),
    'Opt-in required for candidate visibility' => str_contains($code['controller'],
        "->where('available_for_work', true)")
        && str_contains($code['controller'], "->where('consent_to_list', true)"),
    'Licence-required work requires current verification' => str_contains($code['controller'],
        "->where('licence_status', 'verified')") && str_contains($code['controller'],
        "->whereDate('licence_expires_at', '>=', today())"),
    'Employer API does not return patient clinical fields' => !str_contains($code['controller'],
        'PatientMedicalFile') && !str_contains($code['controller'],
        'Prescription') && !str_contains($code['controller'], 'Appointment::'),
    'Tables isolated from Patient users and medical files' => str_contains($code['migration'],
        "Schema::create('workforce_accounts'") && !str_contains($code['migration'],
        "->constrained('users')"),
];
foreach ($checks as $label => $passed) {
    if (! $passed) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
