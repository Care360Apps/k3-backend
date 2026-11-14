<?php

namespace App\Http\Middleware;

use App\Models\Doctor;
use Closure;
use Illuminate\Http\Request;

/**
 * Applies to professional-only clinical and financial actions, not registration,
 * profile editing or independent credential review.
 */
final class EnsureApprovedProfessional
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('api');
        if (! $user) {
            return response()->json(['success' => false, 'code' => 'UNAUTHENTICATED'], 401);
        }
        if (! $user->hasAnyRole(['doctor', 'provider'])) {
            return response()->json(['success' => false, 'code' => 'PROFESSIONAL_ACCESS_DENIED'], 403);
        }

        // A previously issued Passport token must not outlive account suspension.
        if ((int) $user->status !== 1) {
            return response()->json(['success' => false, 'code' => 'PROFESSIONAL_ACCESS_DENIED'], 403);
        }

        $professional = Doctor::query()
            ->where('user_id', $user->id)
            ->where('is_approved', 1)
            ->where('status', 1)
            ->where(function ($query) {
                // Approved legacy doctors may have a NULL role column, but
                // provider accounts must always be explicitly typed.
                $query->whereIn('user_role', ['doctor', 'provider'])
                    ->orWhereNull('user_role');
            })
            ->first();

        $professionalRole = $professional?->user_role
            ?? ($user->hasRole('doctor') ? 'doctor' : null);
        if (! $professional || ! $professionalRole || ! $user->hasRole($professionalRole)) {
            return response()->json(['success' => false, 'code' => 'PROFESSIONAL_NOT_APPROVED'], 403);
        }

        $request->attributes->set('approved_professional_id', $professional->id);
        return $next($request);
    }
}
