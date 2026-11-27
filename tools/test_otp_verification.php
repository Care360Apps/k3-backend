<?php

// Exercise the real OTP service with an isolated clock, user and Laravel facades.
// No database, delivery provider or production account is used.
namespace {
    final class TestClock
    {
        public function __construct(public int $seconds) {}
        public function addMinutes(int $minutes): self { return new self($this->seconds + $minutes * 60); }
        public function greaterThan(self $other): bool { return $this->seconds > $other->seconds; }
    }
    function now(): TestClock { return new TestClock($GLOBALS['otp_time']); }
}

namespace App\Models {
    final class User
    {
        public static ?self $current = null;
        public function __construct() { self::$current = $this; }
        public static function query(): self { return self::$current; }
        public function whereKey($id): self { return $this; }
        public function lockForUpdate(): self { return $this; }
        public function first(): self { return $this; }
        public function refresh(): self { return $this; }
        public int $id = 1;
        public int $verify = 0;
        public ?string $otp = null;
        public ?\TestClock $otp_expires_at = null;
        public int $otp_attempts = 0;
        public function forceFill(array $values): self { foreach ($values as $key => $value) { $this->$key = $value; } return $this; }
        public function save(): bool { return true; }
        public function increment(string $key): void { $this->$key++; }
    }
}

namespace Illuminate\Support\Facades {
    final class DB
    {
        public static function transaction(callable $callback): mixed { return $callback(); }
    }
    final class Hash
    {
        public static function make(string $value): string { return password_hash($value, PASSWORD_BCRYPT); }
        public static function check(string $value, string $hash): bool { return password_verify($value, $hash); }
    }
    final class RateLimiter
    {
        public static array $hits = [];
        public static function tooManyAttempts(string $key, int $limit): bool
        {
            $entry = self::$hits[$key] ?? [0, 0];
            return $entry[1] > $GLOBALS['otp_time'] && $entry[0] >= $limit;
        }
        public static function hit(string $key, int $ttl): void
        {
            $entry = self::$hits[$key] ?? [0, 0];
            if ($entry[1] <= $GLOBALS['otp_time']) { $entry = [0, $GLOBALS['otp_time'] + $ttl]; }
            self::$hits[$key] = [$entry[0] + 1, $entry[1]];
        }
    }
}

namespace {
    require __DIR__.'/../app/Services/OtpService.php';
    use App\Models\User;
    use App\Services\OtpService;
    use Illuminate\Support\Facades\RateLimiter;

    $cases = 0;
    function check(bool $condition, string $message): void
    {
        if (! $condition) { throw new RuntimeException($message); }
    }
    function runCase(string $name, callable $test): void
    {
        $GLOBALS['otp_time'] = 1000;
        RateLimiter::$hits = [];
        $test(new OtpService(), new User());
        $GLOBALS['cases']++;
        echo "PASS: $name\n";
    }

    runCase('six digit code is hashed and expires after ten minutes', function ($service, $user) {
        $code = $service->issue($user);
        check((bool) preg_match('/^[0-9]{6}$/', $code), 'Invalid code length');
        check($user->otp !== $code && password_verify($code, $user->otp), 'Code not hashed');
        check($user->otp_expires_at->seconds === 1600, 'Incorrect expiry');
    });
    runCase('verified accounts cannot receive or redeem signup codes', function ($service, $user) {
        $code = $service->issue($user);
        $user->verify = 1;
        check($service->issue($user) === null, 'Issued for verified user');
        check(! $service->verify($user, $code), 'Verified account redeemed code');
    });
    runCase('success consumes code and prevents replay', function ($service, $user) {
        $code = $service->issue($user);
        check($service->verify($user, $code), 'Valid code rejected');
        check($user->verify === 1 && $user->otp === null && $user->otp_expires_at === null, 'Code not consumed');
        check(! $service->verify($user, $code), 'Code replay accepted');
    });
    runCase('expired code is cleared', function ($service, $user) {
        $code = $service->issue($user);
        $GLOBALS['otp_time'] += 601;
        check(! $service->verify($user, $code) && $user->otp === null, 'Expired code accepted or retained');
    });
    runCase('five wrong guesses block even the correct code', function ($service, $user) {
        $code = $service->issue($user);
        $wrong = $code === '123456' ? '654321' : '123456';
        for ($i = 0; $i < 5; $i++) { check(! $service->verify($user, $wrong), 'Wrong code accepted'); }
        check(! $service->verify($user, $code) && $user->otp === null, 'Attempt limit bypassed');
    });
    runCase('missing expiry cannot be redeemed', function ($service, $user) {
        $code = $service->issue($user);
        $user->otp_expires_at = null;
        check(! $service->verify($user, $code) && $user->otp === null, 'Missing expiry accepted');
    });
    runCase('resend cooldown preserves the current code', function ($service, $user) {
        $code = $service->issue($user);
        check($service->issue($user) === null, 'Immediate resend permitted');
        check(password_verify($code, $user->otp), 'Blocked resend changed code');
        $GLOBALS['otp_time'] += 60;
        check($service->issue($user) !== null, 'Resend blocked after cooldown');
    });
    runCase('hourly send limit survives cooldowns and resets', function ($service, $user) {
        for ($i = 0; $i < 5; $i++) {
            check($service->issue($user) !== null, 'Allowed send blocked');
            $GLOBALS['otp_time'] += 60;
        }
        check($service->issue($user) === null, 'Sixth hourly send permitted');
        $GLOBALS['otp_time'] = 4600;
        check($service->issue($user) !== null, 'Hourly limit did not expire');
    });
    runCase('resend invalidates the prior code hash', function ($service, $user) {
        $first = $service->issue($user);
        $GLOBALS['otp_time'] += 60;
        $second = $service->issue($user);
        check(password_verify($second, $user->otp), 'Replacement not stored');
        if ($first !== $second) { check(! $service->verify($user, $first), 'Old code remains valid'); }
        check($service->verify($user, $second), 'Replacement code rejected');
    });
    runCase('short or missing codes cannot verify an account', function ($service, $user) {
        $service->issue($user);
        foreach ([null, '', '12345', '1234567'] as $code) { check(! $service->verify($user, $code), 'Malformed code accepted'); }
        check($user->verify === 0, 'Malformed input verified user');
    });
    runCase('malformed code with embedded characters is rejected', function ($service, $user) {
        $code = $service->issue($user);
        check(! $service->verify($user, substr($code, 0, 3).'-'.substr($code, 3)), 'Malformed code accepted');
        check($service->verify($user, $code), 'Valid code rejected');
    });
    runCase('code expires at the exact deadline', function ($service, $user) {
        $code = $service->issue($user);
        $GLOBALS['otp_time'] += 600;
        check(! $service->verify($user, $code), 'Code accepted at expiry');
    });
    echo "$cases OTP regression cases passed.\n";
}
