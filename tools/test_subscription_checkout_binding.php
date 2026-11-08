<?php
/**
 * Laravel-independent HMAC checkout-reference regression; synthetic values only.
 * No application secrets, database or payment network access.
 */
declare(strict_types=1);

function config(string $key): ?string
{
    return $GLOBALS['syntheticAppKey'] ?? null;
}

require dirname(__DIR__).'/app/Services/SubscriptionCheckoutReference.php';

use App\Services\SubscriptionCheckoutReference;

$passed = 0;
function check(bool $condition, string $description): void
{
    global $passed;
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$description);
    }
    $passed++;
    echo 'PASS: '.$description.PHP_EOL;
}

$GLOBALS['syntheticAppKey'] = 'base64:'.str_repeat('Q', 43);
$original = SubscriptionCheckoutReference::forSubscription(701, 1001, 500.00);
check((bool) preg_match('/\Ac3sub-701-[a-f0-9]{32}\z/D', $original), 'Reference is a bounded HMAC identifier');
check(hash_equals($original, SubscriptionCheckoutReference::forSubscription(701, 1001, 500.00)),
    'Identical server subscription context gives stable callback reference');
check(! hash_equals($original, SubscriptionCheckoutReference::forSubscription(702, 1001, 500.00)),
    'A different subscription cannot reuse the reference');
check(! hash_equals($original, SubscriptionCheckoutReference::forSubscription(701, 1002, 500.00)),
    'A different payer cannot reuse the reference');
check(! hash_equals($original, SubscriptionCheckoutReference::forSubscription(701, 1001, 501.00)),
    'A modified price cannot reuse the reference');
$GLOBALS['syntheticAppKey'] = null;
try {
    SubscriptionCheckoutReference::forSubscription(701, 1001, 500.00);
    throw new RuntimeException('Invalid application key was accepted');
} catch (RuntimeException $expected) {
    check($expected->getMessage() === 'Secure payment checkout is unavailable.',
        'Missing application key fails closed');
}
echo "$passed synthetic checkout reference cases passed.".PHP_EOL;
