<?php

namespace App\Services;

use Firebase\JWT\JWT;

/**
 * Generates Firebase custom authentication tokens using a service account.
 *
 * Drop your Firebase service account JSON at:
 *   storage/app/firebase-service-account.json
 *
 * Download it from: Firebase Console → Project Settings → Service accounts
 * → Generate new private key.
 */
class FirebaseTokenService
{
    private const FIREBASE_AUDIENCE = 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit';

    private string $serviceAccountEmail;
    private string $privateKey;
    private string $privateKeyId;
    private bool $available = false;

    public function __construct()
    {
        $path = storage_path('app/firebase-service-account.json');
        if (! file_exists($path)) {
            return;
        }

        $json = json_decode(file_get_contents($path), true);
        if (
            empty($json['client_email']) ||
            empty($json['private_key']) ||
            empty($json['private_key_id'])
        ) {
            return;
        }

        $this->serviceAccountEmail = $json['client_email'];
        $this->privateKey          = $json['private_key'];
        $this->privateKeyId        = $json['private_key_id'];
        $this->available           = true;
    }

    /**
     * Returns whether the service account file has been configured.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Create a Firebase custom token for the given UID and optional claims.
     *
     * The UID must be <= 128 chars and may not contain dots, brackets, or slashes.
     *
     * @param  string  $uid     Stable Firebase UID (e.g. "patient_42", "doctor_7")
     * @param  array   $claims  Additional public claims embedded into the token
     * @return string  Signed JWT
     */
    public function createCustomToken(string $uid, array $claims = []): string
    {
        $now = time();

        $payload = [
            'iss'    => $this->serviceAccountEmail,
            'sub'    => $this->serviceAccountEmail,
            'aud'    => self::FIREBASE_AUDIENCE,
            'iat'    => $now,
            'exp'    => $now + 3600,
            'uid'    => $uid,
            'claims' => $claims,
        ];

        return JWT::encode($payload, $this->privateKey, 'RS256', $this->privateKeyId);
    }

    /**
     * Convenience: patient token.
     */
    public function patientToken(int $userId): ?string
    {
        if (! $this->available) {
            return null;
        }

        return $this->createCustomToken("patient_{$userId}", [
            'role'        => 'patient',
            'app_user_id' => $userId,
        ]);
    }

    /**
     * Convenience: doctor token.
     */
    public function doctorToken(int $userId): ?string
    {
        if (! $this->available) {
            return null;
        }

        return $this->createCustomToken("doctor_{$userId}", [
            'role'        => 'doctor',
            'app_user_id' => $userId,
        ]);
    }

    /**
     * Convenience: provider token.
     */
    public function providerToken(int $userId): ?string
    {
        if (! $this->available) {
            return null;
        }

        return $this->createCustomToken("provider_{$userId}", [
            'role'        => 'provider',
            'app_user_id' => $userId,
        ]);
    }
}
