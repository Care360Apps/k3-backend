<?php

namespace App\Console\Commands;

use App\Services\MedicalReportLifecycle;
use App\Services\MedicalReportReleaseGate;
use Illuminate\Console\Command;

class ReportReleaseOperation extends Command
{
    protected $signature = 'medical:report-release {action : verify-http, recover, reconcile or target}';

    protected $description = 'Verify public denial, recover private copies or reconcile report orphans';

    public function handle(MedicalReportLifecycle $lifecycle, MedicalReportReleaseGate $gate): int
    {
        try {
            $result = match ($this->argument('action')) {
                'verify-http' => $lifecycle->verifyHttp(),
                'recover' => $lifecycle->recover(),
                'reconcile' => $lifecycle->reconcile((bool) config('medical_files.auto_quarantine', false)),
                'target' => $gate->targetFingerprint(),
                default => throw new \RuntimeException('Unknown action'),
            };
            $this->line(json_encode(['result' => $result], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(get_class($e) === \RuntimeException::class ? $e->getMessage() : 'Report release operation failed closed; inspect private evidence.');

            return self::FAILURE;
        }
    }
}
