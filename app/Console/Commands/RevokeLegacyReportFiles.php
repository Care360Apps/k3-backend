<?php

namespace App\Console\Commands;

use App\Services\MedicalReportLifecycle;
use Illuminate\Console\Command;

class RevokeLegacyReportFiles extends Command
{
    protected $signature = 'medical:revoke-legacy-reports {--apply} {--confirm-isolated-fixtures : Compatibility flag; does not authorize production}';

    protected $description = 'Reversibly revoke verified legacy public report copies';

    public function handle(MedicalReportLifecycle $lifecycle): int
    {
        if (! $this->option('apply')) {
            return $this->call('medical:verify-report-ledger');
        }
        try {
            $result = $lifecycle->revoke();
            $this->line(json_encode(['result' => $result], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Domain failures contain no patient identifiers; suppress DB and filesystem exception details.
            $this->error(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'Report operation incomplete; preserve copies and inspect private evidence.');

            return self::FAILURE;
        }
    }
}
