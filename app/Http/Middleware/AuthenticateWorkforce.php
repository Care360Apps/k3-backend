<?php

namespace App\Http\Middleware;

use App\Models\WorkforceAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthenticateWorkforce
{
    public function handle(Request $request, Closure $next)
    {
        // These opaque tokens NEVER use the Patient/Doctor Passport guard.
        // A workforce token must not authenticate any clinical API route.
        $rawToken = $request->bearerToken();
        if (! is_string($rawToken) || ! preg_match('/^[A-Za-z0-9_-]{60,70}$/', $rawToken)) {
            return response()->json(['success' => false, 'code' => 'UNAUTHENTICATED'], 401);
        }

        $session = DB::table('workforce_sessions')
            ->where('token_hash', hash('sha256', $rawToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            return response()->json(['success' => false, 'code' => 'UNAUTHENTICATED'], 401);
        }

        $actor = WorkforceAccount::query()->whereKey($session->account_id)
            ->where('is_active', true)->first();
        if (! $actor || ! in_array($actor->account_type, ['candidate', 'employer'], true)) {
            return response()->json(['success' => false, 'code' => 'UNAUTHENTICATED'], 401);
        }

        $request->attributes->set('workforce_actor', $actor);
        $request->attributes->set('workforce_session_id', $session->id);
        return $next($request);
    }
}
