<?php

namespace App\Services;

/** Validation for user-clickable external promotions, never personalized. */
final class SafePartnerLink
{
    public static function isValidHttps(?string $link): bool
    {
        if (! is_string($link) || trim($link) === '' || strlen($link) > 2048) {
            return false;
        }
        $parts = parse_url($link);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') ||
            filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && str_contains($host, '.');
    }
}
