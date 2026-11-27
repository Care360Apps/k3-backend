<?php

/** New isolated staging directory only. Never deploys into the live application. */
final class MedicalReportStage
{
    public static function env(string $file): array
    {
        if (is_link($file) || ! is_file($file) || filesize($file) > 65536 || (PHP_OS_FAMILY !== 'Windows' && (fileperms($file) & 0077) !== 0)) {
            throw new RuntimeException('Environment file must be private and regular.');
        }
        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/\A([A-Z_][A-Z0-9_]*)=(.*)\z/D', $line, $m)) {
                throw new RuntimeException('Unsupported environment syntax.');
            }
            $v = trim($m[2]);
            if (strlen($v) > 1 && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
                $v = substr($v, 1, -1);
            }
            if (str_contains($v, '${')) {
                throw new RuntimeException('Expand environment variables in the private staging file first.');
            }
            if (array_key_exists($m[1], $values)) {
                throw new RuntimeException('Duplicate environment key.');
            }
            $values[$m[1]] = $v;
        }

        return $values;
    }

    public static function validate(string $target, string $envFile, string $live): array
    {
        if (PHP_VERSION_ID < 80200) {
            throw new RuntimeException('PHP 8.2 or newer is required.');
        }
        foreach (['pdo', 'openssl', 'fileinfo', 'mbstring', 'xml', 'gd'] as $extension) {
            if (! extension_loaded($extension)) {
                throw new RuntimeException('Required PHP extension unavailable.');
            }
        }
        $live = realpath($live);
        if (! $live || ! is_file($live.'/.env')) {
            throw new RuntimeException('Live root must exist for read-only isolation comparison.');
        }
        $parent = dirname($target);
        $allowed = dirname($live).DIRECTORY_SEPARATOR.'care360-staging';
        if ($parent !== $allowed || ! preg_match('/\A[a-zA-Z0-9_-]+\z/D', basename($target)) || is_link($allowed) || is_link($target)) {
            throw new RuntimeException('Choose a new release directory directly under care360-staging.');
        }
        $stage = self::env($envFile);
        $production = self::env($live.'/.env');
        if (($stage['APP_ENV'] ?? '') !== 'staging' || ($stage['APP_DEBUG'] ?? '') !== 'false'
            || ($stage['K3_SYNTHETIC_MEDICAL_MIGRATION'] ?? '') !== 'true'
            || ($stage['MEDICAL_REPORT_PRODUCTION_ENABLED'] ?? 'false') !== 'false'
            || ($stage['MEDICAL_REPORT_AUTO_QUARANTINE'] ?? 'false') !== 'false'
            || ($stage['PRIVATE_REPORT_WRITES'] ?? '') !== 'true'
            || ! str_starts_with($stage['APP_URL'] ?? '', 'https://')
            || empty($stage['APP_KEY']) || ($stage['APP_KEY'] ?? '') === ($production['APP_KEY'] ?? '')
            || strtolower((string) parse_url($stage['APP_URL'] ?? '', PHP_URL_HOST)) === strtolower((string) parse_url($production['APP_URL'] ?? '', PHP_URL_HOST))
            || empty($stage['DB_DATABASE']) || ($stage['DB_DATABASE'] ?? '') === ($production['DB_DATABASE'] ?? '')) {
            throw new RuntimeException('Staging isolation or disabled-production controls are invalid.');
        }
        if (($stage['MAIL_MAILER'] ?? '') !== 'array' || ($stage['QUEUE_CONNECTION'] ?? '') !== 'sync') {
            throw new RuntimeException('Use synthetic email and synchronous staging jobs.');
        }
        if (($stage['CHECKOUTPAY_SANDBOX_ENABLED'] ?? 'false') !== 'false' || ($stage['CARE360_WALLET_ENABLED'] ?? 'false') !== 'false') {
            throw new RuntimeException('Financial activation is prohibited.');
        }

        return $stage;
    }

    private static function run(array $command, string $cwd): void
    {
        $log = $cwd.'/storage/app/private/release-backups/commands.log';
        if (is_link($log)) {
            throw new RuntimeException('Unsafe command log.');
        }
        $handle = fopen($log, 'ab');
        if (! $handle || ! chmod($log, 0600)) {
            throw new RuntimeException('Private log unavailable.');
        }fclose($handle);
        // Laravel's dotenv loader does not replace exported variables. Never let
        // a production DB/APP variable inherited from the shell override staging.
        $environment = [];
        foreach (getenv() as $key => $value) {
            if (in_array(strtoupper($key), ['PATH', 'HOME', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR', 'OPENSSL_CONF', 'COMPOSER_CACHE_DIR'], true)) {
                $environment[$key] = $value;
            }
        }
        $environment = array_merge($environment, self::env($cwd.'/.env'), [
            'APP_ENV' => 'staging', 'APP_DEBUG' => 'false',
            'MEDICAL_REPORT_PRODUCTION_ENABLED' => 'false',
            'MEDICAL_REPORT_AUTO_QUARANTINE' => 'false',
        ]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'ab'], 2 => ['file', $log, 'ab']], $pipes, $cwd, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Staging command unavailable.');
        }
        fclose($pipes[0]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Staging command failed; inspect the isolated private command log.');
        }
    }

    private static function database(array $e): PDO
    {
        if (($e['DB_CONNECTION'] ?? 'mysql') === 'mysql') {
            $pdo = new PDO('mysql:host='.($e['DB_HOST'] ?? 'localhost').';port='.($e['DB_PORT'] ?? '3306').';dbname='.$e['DB_DATABASE'].';charset=utf8mb4', $e['DB_USERNAME'] ?? '', $e['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } elseif (($e['DB_CONNECTION'] ?? '') === 'sqlite') {
            if (! is_file($e['DB_DATABASE'])) {
                throw new RuntimeException('Existing isolated synthetic schema required.');
            }
            $pdo = new PDO('sqlite:'.$e['DB_DATABASE'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } else {
            throw new RuntimeException('Unsupported staging database driver.');
        }
        $pdo->query('SELECT id, report_image FROM appointment LIMIT 0');

        return $pdo;
    }

    private static function backup(PDO $pdo, string $path): void
    {
        $out = fopen($path, 'xb');
        if (! $out || ! chmod($path, 0600)) {
            throw new RuntimeException('Private database backup unavailable.');
        }
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        try {
            fwrite($out, $mysql ? "SET FOREIGN_KEY_CHECKS=0;\n" : "PRAGMA foreign_keys=OFF;\n");
            $tables = $mysql ? $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                if (! preg_match('/\A[a-zA-Z0-9_]+\z/D', $table)) {
                    throw new RuntimeException('Unsafe table identifier.');
                }
                $q = $mysql ? '`' : '"';
                $quoted = $q.$table.$q;
                $ddl = $mysql ? $pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM)[1] : $pdo->query('SELECT sql FROM sqlite_master WHERE name='.$pdo->quote($table))->fetchColumn();
                fwrite($out, $ddl.";\n");
                foreach ($pdo->query('SELECT * FROM '.$quoted) as $raw) {
                    $row = array_filter($raw, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
                    $columns = implode(',', array_map(fn ($c) => $q.str_replace($q, $q.$q, $c).$q, array_keys($row)));
                    $values = implode(',', array_map(fn ($v) => $v === null ? 'NULL' : (str_contains((string) $v, "\0") ? "X'".bin2hex((string) $v)."'" : $pdo->quote((string) $v)), array_values($row)));
                    if (fwrite($out, 'INSERT INTO '.$quoted.' ('.$columns.') VALUES ('.$values.");\n") === false) {
                        throw new RuntimeException('Incomplete database backup.');
                    }
                }
            }
            if (! fflush($out) || (function_exists('fsync') && ! fsync($out))) {
                throw new RuntimeException('Database backup not durable.');
            }
        } finally {
            fclose($out);
        }
    }

    public static function install(string $source, string $target, string $envFile, string $live): void
    {
        $stage = self::validate($target, $envFile, $live);
        $manifest = json_decode(file_get_contents($source.'/medical-release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $file => $sha) {
            if (str_contains($file, '..') || str_starts_with($file, '/') || is_link($source.'/'.$file) || ! hash_equals($sha, hash_file('sha256', $source.'/'.$file))) {
                throw new RuntimeException('Package integrity failed.');
            }
        }
        if (is_dir($target)) {
            $marker = $target.'/storage/app/private/release-backups/installed.json';
            if (! is_file($marker) || json_decode(file_get_contents($marker), true)['manifest'] !== hash_file('sha256', $source.'/medical-release-manifest.json') || hash_file('sha256', $target.'/.env') !== hash_file('sha256', $envFile)) {
                throw new RuntimeException('Existing staging release differs; choose a new target.');
            }
            self::run([PHP_BINARY, 'artisan', 'medical:verify-report-ledger'], $target);
            echo "Existing matching staging release verified; no files overwritten.\n";

            return;
        }
        $pdo = self::database($stage);
        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700)) {
            throw new RuntimeException('Staging parent unavailable.');
        }
        if (realpath(dirname($target)) !== dirname($target) || ! mkdir($target, 0700)) {
            throw new RuntimeException('Unsafe staging target.');
        }
        foreach ($manifest['files'] as $file => $sha) {
            $destination = $target.'/'.$file;
            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }
            if (! copy($source.'/'.$file, $destination) || ! hash_equals($sha, hash_file('sha256', $destination))) {
                throw new RuntimeException('Stage copy failed.');
            }
        }
        copy($envFile, $target.'/.env');
        chmod($target.'/.env', 0600);
        foreach (['storage/app/private/release-backups', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'bootstrap/cache', 'public/images/upload'] as $dir) {
            if (! is_dir($target.'/'.$dir)) {
                mkdir($target.'/'.$dir, 0700, true);
            }
        }
        $backups = $target.'/storage/app/private/release-backups';
        self::backup($pdo, $backups.'/staging-before.sql');
        copy($envFile, $backups.'/environment-before');
        chmod($backups.'/environment-before', 0600);
        self::run(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-dev', '--no-scripts', '--no-progress'], $target);
        foreach ($manifest['files'] as $file => $sha) {
            if (str_ends_with($file, '.php') && ! str_contains($file, '.blade.')) {
                self::run([PHP_BINARY, '-l', $file], $target);
            }
        }
        self::run([PHP_BINARY, 'artisan', 'package:discover', '--no-interaction'], $target);
        self::run([PHP_BINARY, 'artisan', 'config:clear'], $target);
        self::run([PHP_BINARY, 'artisan', 'route:clear'], $target);
        self::run([PHP_BINARY, 'artisan', 'view:clear'], $target);
        foreach (['2026_11_27_030000_create_appointment_report_migration_ledger.php', '2026_11_27_040000_create_report_file_operations.php'] as $migration) {
            self::run([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction', '--path=database/migrations/'.$migration], $target);
        }
        self::run([PHP_BINARY, 'artisan', 'medical:verify-report-ledger'], $target);
        file_put_contents($backups.'/installed.json', json_encode(['manifest' => hash_file('sha256', $source.'/medical-release-manifest.json'), 'database_backup_sha256' => hash_file('sha256', $backups.'/staging-before.sql'), 'production_activation' => false], JSON_PRETTY_PRINT));
        chmod($backups.'/installed.json', 0600);
        echo "Isolated staging source, dependency, syntax and ledger checks passed. Production was not changed.\n";
        echo "Point an isolated HTTPS staging host at the new public directory. Complete synthetic app and HTTP acceptance; do not activate production.\n";
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $args = getopt('', ['target:', 'env-file:', 'live-root:', 'validate-only']);
        foreach (['target', 'env-file', 'live-root'] as $name) {
            if (empty($args[$name])) {
                throw new RuntimeException('Required arguments: --target, --env-file, --live-root.');
            }
        }
        if (isset($args['validate-only'])) {
            MedicalReportStage::validate($args['target'], $args['env-file'], $args['live-root']);
            echo "Staging isolation configuration verified. No deployment performed.\n";
        } else {
            MedicalReportStage::install(dirname(__DIR__), $args['target'], $args['env-file'], $args['live-root']);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e) === RuntimeException::class ? $e->getMessage()."\n" : "Staging failed closed; inspect privately.\n");
        exit(1);
    }
}
