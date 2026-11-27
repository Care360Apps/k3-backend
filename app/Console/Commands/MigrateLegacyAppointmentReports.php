<?php

namespace App\Console\Commands;

use App\Services\MedicalReportLifecycle;
use Illuminate\Console\Command;

class MigrateLegacyAppointmentReports extends Command
{
    protected $signature = 'medical:migrate-appointment-reports {--apply}';

    protected $description = 'Copy and transactionally migrate legacy appointment reports';

    public function handle(MedicalReportLifecycle $lifecycle): int
    {
        if (! $this->option('apply')) {
            return $this->call('medical:report-migration-audit');
        }
        try {
            $result = $lifecycle->migrate();
            $this->line(json_encode(['result' => $result], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Domain failures contain no patient identifiers; suppress DB and filesystem exception details.
            $this->error(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'Report operation incomplete; preserve copies and inspect private evidence.');

            return self::FAILURE;
        }
    }
}
