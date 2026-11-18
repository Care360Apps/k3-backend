<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WorkforcePilotEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('workforce.enabled', false)) {
            // Fail closed rather than exposing private candidate resources.
            return response()->json([
                'success' => false,
                'code' => 'WORKFORCE_NOT_OPEN',
                'message' => 'Care360 Workforce applications are not open yet.',
            ], 503);
        }

        return $next($request);
    }
}
