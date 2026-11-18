<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CareerWorkflowsEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('career.enabled', false), 503);

        return $next($request);
    }
}
