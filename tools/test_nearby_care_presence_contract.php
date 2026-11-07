<?php
// Source-only safety contract. Follow with Laravel/MySQL staging integration
// tests, an approved provider account and synthetic registered coordinates.
$ctrl = file_get_contents(__DIR__.'/../app/Http/Controllers/NearbyCareController.php');
$routes = file_get_contents(__DIR__.'/../routes/api.php');
$migration = file_get_contents(__DIR__.'/../database/migrations/2026_11_07_000001_create_professional_service_presence_table.php');
if ($ctrl === false || $routes === false || $migration === false) {
    throw new RuntimeException('Missing nearby-care source');
}
function requireNearby(string $source, string $needle): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Nearby-care safety contract missing: '.$needle);
    }
}
requireNearby($migration, "->default(false)");
requireNearby($migration, "->unique()");
requireNearby($migration, "public_location_consent_at");
requireNearby($migration, "on_duty_until");
requireNearby($routes, "Route::post('nearby-care'");
requireNearby($routes, "Route::post('professional/presence'");
requireNearby($routes, "Route::middleware('auth:api')->group(function () {");
requireNearby($ctrl, "hasRole('doctor')");
requireNearby($ctrl, "hasRole('provider')");
requireNearby($ctrl, '(int) $doctor->is_approved === 1');
requireNearby($ctrl, '(int) $doctor->is_filled === 1');
requireNearby($ctrl, "public_location_consent_at");
requireNearby($ctrl, "'confirm_public_location' => 'required_if:accepting_requests,1|accepted'");
requireNearby($ctrl, "->where('p.accepting_requests', 1)");
requireNearby($ctrl, "->where('p.on_duty_until', '>', now())");
requireNearby($ctrl, "->where('d.is_approved', 1)");
requireNearby($ctrl, "->havingRaw('distance_km <= service_radius_km')");
requireNearby($ctrl, "'accepting_requests_not_booking_confirmed'");
requireNearby($ctrl, '$doctor->offers_video_call === null');
requireNearby($ctrl, "->whereNull('d.offers_video_call')");
requireNearby($ctrl, '\'offers_video_call\' => $row->offers_video_call === null');
requireNearby($ctrl, 'round((float) $row->public_lat, 2)');
requireNearby($ctrl, 'round((float) $row->public_lng, 2)');
if (str_contains($ctrl, "device_gps") || str_contains($ctrl, "patient_address") ||
    str_contains($ctrl, "return response()->json(['data' => $doctor])")) {
    throw new RuntimeException('Private location or clinical metadata in public nearby response');
}
echo 'PASS: default-off, expiring and consented nearby-care listing safety contract'.PHP_EOL;
