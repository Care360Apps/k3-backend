<?php

namespace App\Support;

/**
 * Role eligibility is controlled by the server, never a candidate-entered
 * display title. "Support" roles permit only appropriately supervised tasks.
 */
final class WorkforceRoles
{
    public const ROLES = [
        'care_assistant' => false,
        'home_care_assistant' => false,
        'supervised_intern' => false,
        'phlebotomy_support' => false,
        'laboratory_assistant' => false,
        'medical_records_assistant' => false,
        'nurse' => true,
        'community_health_practitioner' => true,
        'medical_laboratory_scientist' => true,
        'pharmacist' => true,
        'doctor' => true,
    ];

    public static function licenceRequired(): array
    {
        return array_keys(array_filter(self::ROLES));
    }

    public static function isLicenceRequired(string $role): bool
    {
        return self::ROLES[$role] ?? true; // Unknown roles fail closed.
    }
}
