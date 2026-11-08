<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Read-only configuration report for an authorized Care360 operator.
 * Never prints APP_KEY, database credentials, Passport keys or environment
 * values. Passing these local checks is NOT a penetration-test signoff.
 */
class Care360SecurityPreflight extends Command
{
    protected $signature = 'care360:security-preflight';

    protected $description = 'Report privacy and deployment safety flags without exposing secrets';

    public function handle(): int
    {
        $publicRoot = realpath(public_path());
        $storageRoot = realpath(storage_path('app'));
        $privateIsolated = $publicRoot !== false
            && $storageRoot !== false
            && $storageRoot !== $publicRoot
            && ! str_starts_with($storageRoot, $publicRoot.DIRECTORY_SEPARATOR);

        $checks = [
            ['Production application environment', app()->environment('production')],
            ['Laravel debug disabled', config('app.debug') === false],
            ['Configured application URL uses HTTPS',
                str_starts_with((string) config('app.url'), 'https://')],
            ['Laravel application key configured',
                is_string(config('app.key')) &&
                strlen((string) config('app.key')) >= 20],
            ['Private storage outside public webroot', $privateIsolated],
            ['Environment file not group/world writable',
                $this->notWritableByOthers(base_path('.env'))],
            ['Passport RSA private key not group/world readable',
                $this->privateKeyPermissions(storage_path('oauth-private.key'))],
        ];

        $failed = 0;
        foreach ($checks as [$label, $ok]) {
            $this->line(($ok ? 'PASS' : 'REVIEW').' | '.$label);
            if (! $ok) $failed++;
        }

        $this->warn(
            'NOT VERIFIED HERE: external old medical PDF/report/image URLs, '.
            'credential rotation history, real payment/OTP verification, '.
            'doctor/provider BOLA, third-party SDKs and published APKs.'
        );
        $this->warn(
            'Do not upload this environment, clinical data or Passport keys '.
            'to a GitHub issue. Share only this flag-only preflight output.'
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function notWritableByOthers(string $file): bool
    {
        if (! is_file($file) || is_link($file)) return false;
        return ((fileperms($file) & 0022) === 0);
    }

    private function privateKeyPermissions(string $file): bool
    {
        if (! is_file($file) || is_link($file)) return false;
        // On supported POSIX servers a Passport private key should be 0600.
        return ((fileperms($file) & 0077) === 0);
    }
}
