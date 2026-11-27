<?php

namespace App\Console\Commands;

use App\Services\MedicalReportLifecycle;
use Illuminate\Console\Command;

class VerifyReportMigrationLedger extends Command
{
    protected $signature = 'medical:verify-report-ledger';

    protected $description = 'Verify migration evidence before and after revocation';

    public function handle(MedicalReportLifecycle $lifecycle): int
    {
        try {
            $result = $lifecycle->verify();
            $this->line(json_encode(['result' => $result], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Domain failures contain no patient identifiers; suppress DB and filesystem exception details.
            $this->error(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'Report operation incomplete; preserve copies and inspect private evidence.');

            return self::FAILURE;
        }
    }
}
