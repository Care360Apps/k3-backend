<?php

namespace App\Services;

final class MedicalReportReleaseGate
{
    public function targetFingerprint(): string
    {
        $connection = config('database.default');

        return hash('sha256', json_encode([
            config('app.url'), realpath(storage_path('app')), $connection,
            config("database.connections.$connection.host"),
            config("database.connections.$connection.database"),
        ], JSON_THROW_ON_ERROR));
    }

    public function authorize(string $action, bool $maintenance = true): void
    {
        if ($maintenance && ! app()->isDownForMaintenance()) {
            throw new \RuntimeException('Maintenance and stopped writers are required.');
        }
        if (app()->environment('staging', 'testing')) {
            if (! config('medical_files.allow_synthetic_migration', false)) {
                throw new \RuntimeException('Isolated synthetic staging is not enabled.');
            }

            return;
        }
        if (! app()->environment('production') || ! config('medical_files.production_release_enabled', false)) {
            throw new \RuntimeException('Production report activation is disabled.');
        }
        if (in_array($action, ['migrate', 'revoke'], true) && ! config('medical_files.private_report_writes', false)) {
            throw new \RuntimeException('All report writers must be private before production migration.');
        }
        $file = config('medical_files.release_approval_file');
        $public = realpath(public_path());
        $real = is_string($file) ? realpath($file) : false;
        if (! $real || is_link($file) || ! is_file($real) || filesize($real) > 16384
            || ! $public || $real === $public || str_starts_with($real, $public.DIRECTORY_SEPARATOR)
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($real) & 0077) !== 0)) {
            throw new \RuntimeException('Private operator approval is missing or unsafe.');
        }
        $approval = json_decode(file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
        $evidence = config('medical_files.staging_acceptance_sha256');
        if (($approval['approved'] ?? false) !== true
            || ($approval['backup_restore_verified'] ?? false) !== true
            || ($maintenance && ($approval['writers_stopped'] ?? false) !== true)
            || ! is_string($evidence) || ! preg_match('/\A[a-f0-9]{64}\z/D', $evidence)
            || ($approval['staging_acceptance_sha256'] ?? '') !== $evidence
            || ($approval['target_sha256'] ?? '') !== $this->targetFingerprint()
            || ! config('medical_files.deployment_id')
            || ($approval['deployment_id'] ?? '') !== config('medical_files.deployment_id')
            || strtotime($approval['expires_at'] ?? '') <= time()
            || ! in_array($action, $approval['actions'] ?? [], true)) {
            throw new \RuntimeException('Approval is expired or does not match this deployment and action.');
        }
    }

    public function privateWritesEnabled(): bool
    {
        if (! config('medical_files.private_report_writes', false)) {
            return false;
        }
        if (app()->environment('production')) {
            $this->authorize('activate', false);
        }

        return true;
    }
}
