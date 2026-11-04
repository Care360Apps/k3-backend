<?php

namespace App\Services;

/**
 * Interim RTC access-token policy for scheduled one-to-one consultations.
 * Clients must still implement token renewal for calls over this interval.
 */
final class AgoraSessionPolicy
{
    public const TOKEN_TTL_SECONDS = 3600;
}
