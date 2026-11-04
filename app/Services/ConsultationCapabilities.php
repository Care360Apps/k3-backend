<?php

namespace App\Services;

use App\Models\Doctor;

/**
 * Consultation types offered by the professional behind the legacy "doctor"
 * table. Capability is determined by both role and the approved profile flags;
 * clinical permissions (e.g. prescribing) remain separate.
 */
final class ConsultationCapabilities
{
    public static function allowedVisitTypes(Doctor $professional): array
    {
        $isProvider = $professional->user_role === 'provider';
        $allowed = [];
        if ((int) ($professional->offers_video_call ?? ($isProvider ? 0 : 1)) === 1) {
            $allowed[] = 'video_call';
        }
        // Doctor accounts cannot receive home-visit bookings.
        if ($isProvider && (int) ($professional->offers_home_visit ?? 0) === 1) {
            $allowed[] = 'home_visit';
        }

        return $allowed;
    }

    public static function canBook(Doctor $professional, string $visitType): bool
    {
        return in_array($visitType, self::allowedVisitTypes($professional), true);
    }
}
