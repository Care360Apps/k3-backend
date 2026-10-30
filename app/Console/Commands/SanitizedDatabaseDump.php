<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Produces a SQL dump that is safe to hand to third parties: third-party credentials in `settings` are blanked and
 * token tables are exported without rows. After importing, run `php artisan passport:keys --force` and
 * `php artisan passport:client --personal`, then re-enter the gateway/mail keys in the admin panel.
 */
class SanitizedDatabaseDump extends Command
{
    protected $signature = 'db:sanitized-dump {path : File to write the SQL dump to} {--mysqldump=mysqldump : Path to the mysqldump binary}';

    protected $description = 'Dump the database with secrets and authentication tokens removed';

    private const STRUCTURE_ONLY_TABLES = [
        'settings',
        'oauth_access_tokens',
        'oauth_refresh_tokens',
        'oauth_auth_codes',
        'oauth_clients',
        'oauth_personal_access_clients',
        'password_resets',
        'zoom_oauth',
        'sessions',
    ];

    private const SECRET_SETTING_COLUMNS = [
        'stripe_secret_key', 'paypal_secret_key', 'paypal_client_id', 'razor_key', 'flutterwave_key',
        'flutterwave_encryption_key', 'paystack_public_key', 'map_key', 'twilio_auth_token', 'mail_username',
        'mail_password', 'patient_app_id', 'patient_auth_key', 'patient_api_key', 'doctor_app_id', 'doctor_auth_key',
        'doctor_api_key', 'license_code', 'agora_app_id', 'agora_app_certificate', 'zoom_client_id', 'zoom_client_secret',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');
        $connection = config('database.connections.'.config('database.default'));
        $database = $connection['database'];
        $existing = array_map(fn ($t) => array_values((array) $t)[0], DB::select('SHOW TABLES'));
        $structureOnly = array_values(array_intersect(self::STRUCTURE_ONLY_TABLES, $existing));

        $base = [$this->option('mysqldump'), '--single-transaction', '--skip-lock-tables', '--no-tablespaces',
            '-h', $connection['host'], '-P', (string) $connection['port'], '-u', $connection['username']];
        $env = ['MYSQL_PWD' => (string) $connection['password']];

        $ignore = array_map(fn ($t) => '--ignore-table='.$database.'.'.$t, $structureOnly);
        if (! $this->runInto(array_merge($base, $ignore, [$database]), $env, $path, false)) {
            return self::FAILURE;
        }
        if ($structureOnly && ! $this->runInto(array_merge($base, ['--no-data', $database], $structureOnly), $env, $path, true)) {
            return self::FAILURE;
        }

        file_put_contents($path, $this->settingsInserts(), FILE_APPEND);
        @chmod($path, 0600);
        $this->info("Sanitized dump written to {$path}");

        return self::SUCCESS;
    }

    private function runInto(array $command, array $env, string $path, bool $append): bool
    {
        $process = new Process($command, null, $env, null, 3600);
        $handle = fopen($path, $append ? 'ab' : 'wb');
        $process->run(function ($type, $buffer) use ($handle) {
            if ($type === Process::OUT) {
                fwrite($handle, $buffer);
            } else {
                $this->getOutput()->getErrorStyle()->write($buffer);
            }
        });
        fclose($handle);

        if (! $process->isSuccessful()) {
            $this->error('mysqldump failed with exit code '.$process->getExitCode());

            return false;
        }

        return true;
    }

    private function settingsInserts(): string
    {
        $pdo = DB::getPdo();
        $sql = "\n-- settings with third-party credentials removed\n";
        foreach (DB::table('settings')->get() as $row) {
            $row = (array) $row;
            foreach (self::SECRET_SETTING_COLUMNS as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = null;
                }
            }
            $columns = implode(',', array_map(fn ($c) => '`'.str_replace('`', '``', $c).'`', array_keys($row)));
            $values = implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row));
            $sql .= "INSERT INTO `settings` ({$columns}) VALUES ({$values});\n";
        }

        return $sql;
    }
}
