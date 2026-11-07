<?php

/**
 * Offline source contract checks: no real accounts, Google requests or API keys.
 * Run: php tools/test_places_gateway_contract.php
 */
$root = dirname(__DIR__);
$controller = file_get_contents($root.'/app/Http/Controllers/PatientPlaceSearchController.php');
$routes = file_get_contents($root.'/routes/api.php');
$services = file_get_contents($root.'/config/services.php');
$settings = file_get_contents($root.'/app/Http/Controllers/UserApiController.php');
if ($controller === false || $routes === false || $services === false || $settings === false) {
    fwrite(STDERR, "Missing Places proxy contract files\n"); exit(1);
}

$conditions = [
    'Both routes have auth and limits' =>
        str_contains($routes, "Route::post('places/autocomplete'")
        && str_contains($routes, "Route::post('places/details'")
        && str_contains($routes, "->middleware('throttle:20,1')"),
    'Google key stays server-side' =>
        str_contains($services, "env('K3_PLACES_SERVER_API_KEY', '')")
        && str_contains($controller, "config('services.google_places.key'"),
    'New API only, hardcoded Google upstream' =>
        str_contains($controller, 'https://places.googleapis.com/v1/places:autocomplete')
        && str_contains($controller, 'https://places.googleapis.com/v1/places/')
        && !str_contains($controller, 'http://'),
    'Minimal response and no original upstream response body' =>
        str_contains($controller, "'place_id' => $".'id')
        && str_contains($controller, "'latitude' => (float)")
        && !str_contains($controller, 'return $response->body()'),
    'No original web-service key in public settings' =>
        !str_contains($settings, "'paypal_client_id', 'map_key'"),
    'Session tokens validated and Google calls bounded' =>
        str_contains($controller, "'session_token' => ['required', 'uuid']")
        && str_contains($controller, '->timeout(6)->connectTimeout(3)'),
    'AI remains feature-flagged and authenticated' =>
        str_contains($routes, "Route::post('/ai/guide'")
        && str_contains($routes, "'auth:api', 'throttle:5,1'")
        && str_contains($services, "env('K3_AI_GUIDE_ENABLED', false)"),
];
foreach ($conditions as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
