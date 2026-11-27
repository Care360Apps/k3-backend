<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sign-up verification codes. Codes only exist for accounts that are not verified yet, so an OTP can never be
 * used to sign in to an established account.
 */
class OtpService
{
    public const LENGTH = 6;

    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const SENDS_PER_HOUR = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    /** Returns the new plain code, or null when the user is verified already or has asked for too many codes. */
    public function issue(User $user): ?string
    {
        if ((int) $user->verify === 1) {
            return null;
        }
        $cooldownKey = 'otp-send-cooldown:'.$user->id;
        $hourlyKey = 'otp-send-hourly:'.$user->id;
        if (RateLimiter::tooManyAttempts($cooldownKey, 1) || RateLimiter::tooManyAttempts($hourlyKey, self::SENDS_PER_HOUR)) {
            return null;
        }
        RateLimiter::hit($cooldownKey, self::RESEND_COOLDOWN_SECONDS);
        RateLimiter::hit($hourlyKey, 3600);

        $code = (string) random_int(10 ** (self::LENGTH - 1), 10 ** self::LENGTH - 1);
        $user->forceFill([
            'otp' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'otp_attempts' => 0,
        ])->save();

        return $code;
    }

    /** Checks the code, consuming it on success and invalidating it after too many wrong guesses. Marks the user verified. */
    public function verify(User $user, ?string $code): bool
    {
        $code = trim((string) $code);
        if ((int) $user->verify === 1 || ! $user->otp || ! preg_match('/^[0-9]{6}$/D', $code)) {
            return false;
        }
        if (! $user->otp_expires_at || ! $user->otp_expires_at->greaterThan(now()) || $user->otp_attempts >= self::MAX_ATTEMPTS) {
            $this->clear($user);

            return false;
        }
        if (! Hash::check($code, $user->otp)) {
            $user->increment('otp_attempts');

            return false;
        }
        $user->forceFill([
            'verify' => 1, 'otp' => null, 'otp_expires_at' => null, 'otp_attempts' => 0,
        ])->save();

        return true;
    }

    /** Joins the six single-digit inputs of the web OTP forms. */
    public static function fromDigits(array $input): string
    {
        return implode('', array_map(fn ($i) => (string) ($input['digit_'.$i] ?? ''), range(1, self::LENGTH)));
    }

    private function clear(User $user): void
    {
        $user->forceFill(['otp' => null, 'otp_expires_at' => null, 'otp_attempts' => 0])->save();
    }
}
