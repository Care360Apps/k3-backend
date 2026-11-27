<?php

namespace App\Console\Commands;

use App\Services\MedicalReportLifecycle;
use Illuminate\Console\Command;

class QuarantinePrivateReportOrphans extends Command
{
    protected $signature = 'medical:report-orphan-quarantine {--apply}';

    protected $description = 'Observe and safely quarantine aged unreferenced reports';

    public function handle(MedicalReportLifecycle $lifecycle): int
    {
        try {
            $result = $lifecycle->reconcile((bool) $this->option('apply'));
            $this->line(json_encode(['result' => $result], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Domain failures contain no patient identifiers; suppress DB and filesystem exception details.
            $this->error(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'Report operation incomplete; preserve copies and inspect private evidence.');

            return self::FAILURE;
        }
    }
}
