<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-only guard for the Patient release migration.
 * An end-to-end auth/Passport + data-erasure staging test is still required.
 */
class PatientAccountDeletionRouteContractTest extends TestCase
{
    public function test_post_deletion_route_is_authenticated_and_throttled(): void
    {
        $route = file_get_contents(dirname(__DIR__, 2).'/routes/api.php');
        $this->assertNotFalse($route);
        $start = strpos($route, "Route::middleware('auth:api')->group(function () {");
        $deletion = strpos($route, "Route::post('/delete-account'");
        $this->assertNotFalse($start);
        $this->assertNotFalse($deletion);
        $this->assertGreaterThan($start, $deletion);
        $this->assertStringContainsString(
            "Route::post('/delete-account', [UserApiController::class, 'deleteAccount'])",
            $route
        );
        $this->assertStringContainsString("->middleware('throttle:3,10')", $route);
        $this->assertStringContainsString(
            "Route::get('/delete-account', [UserApiController::class, 'deleteAccount'])",
            $route
        );
    }

    public function test_erasure_service_and_appointment_guard_remain_intact(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/UserApiController.php');
        $this->assertStringContainsString('Patient access required', $controller);
        $this->assertStringContainsString('$user->roles()->exists()', $controller);
        $this->assertStringContainsString('Appointment::hasUpcomingForPatient', $controller);
        $this->assertStringContainsString('(new AccountErasureService)->erase($user)', $controller);
    }
}
