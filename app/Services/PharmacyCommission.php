<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Default 15% Care360 pharmacy service commission on medicines only.
 * Delivery fees are outside this split and must be priced/disbursed separately.
 */
final class PharmacyCommission
{
    public const DEFAULT_PERCENT = 15.0;

    public static function rate($configuredPercent): float
    {
        if ($configuredPercent === null || $configuredPercent === '') {
            return self::DEFAULT_PERCENT;
        }
        if (! is_numeric($configuredPercent)) {
            throw new InvalidArgumentException('Invalid pharmacy commission configuration.');
        }
        $rate = (float) $configuredPercent;
        if (! is_finite($rate) || $rate < 0 || $rate > 100) {
            throw new InvalidArgumentException('Pharmacy commission must be between 0 and 100%.');
        }

        return $rate;
    }

    /** @return array{rate: float, platform: float, pharmacy: float} */
    public static function split(float $medicineSubtotal, $configuredPercent): array
    {
        if (! is_finite($medicineSubtotal) || $medicineSubtotal < 0) {
            throw new InvalidArgumentException('Medicine subtotal cannot be negative.');
        }
        $rate = self::rate($configuredPercent);
        $subtotal = round($medicineSubtotal, 2);
        $platform = round($subtotal * $rate / 100, 2);

        return [
            'rate' => $rate,
            'platform' => $platform,
            'pharmacy' => round($subtotal - $platform, 2),
        ];
    }
}
