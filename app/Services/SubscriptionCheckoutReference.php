<?php

namespace App\Services;

use RuntimeException;

/**
 * Binds a hosted subscription checkout to one server-created subscription,
 * owner and price. Never accept a customer-controlled tx_ref as proof.
 */
final class SubscriptionCheckoutReference
{
    public static function forSubscription(int $subscriptionId, int $userId, float $amount): string
    {
        $secret = config('app.key');
        if ($subscriptionId <= 0 || $userId <= 0 || ! is_finite($amount)
            || $amount <= 0 || ! is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('Secure payment checkout is unavailable.');
        }
        $amountMinor = (int) round($amount * 100);
        $binding = $subscriptionId.'|'.$userId.'|'.$amountMinor;

        return 'c3sub-'.$subscriptionId.'-'.substr(hash_hmac('sha256', $binding, $secret), 0, 32);
    }
}
