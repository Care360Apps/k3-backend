<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MedicalReportStageTest extends TestCase
{
    private string $root;

    private array $values;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('tools/medical_report_stage.php');
        $path = base_path('storage/framework/testing/stage-package-'.bin2hex(random_bytes(6)));
        File::makeDirectory($path, 0700, true);
        $this->root = realpath($path);
        File::makeDirectory($this->root.DIRECTORY_SEPARATOR.'live', 0700);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'live'.DIRECTORY_SEPARATOR.'.env', "APP_ENV=production\nAPP_URL=https://live.example.org\nAPP_KEY=synthetic-live\nDB_DATABASE=synthetic_live\n");
        chmod($this->root.DIRECTORY_SEPARATOR.'live'.DIRECTORY_SEPARATOR.'.env', 0600);
        $this->values = ['APP_ENV' => 'staging', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://staging.example.org', 'APP_KEY' => 'synthetic-stage', 'DB_DATABASE' => 'synthetic_stage', 'K3_SYNTHETIC_MEDICAL_MIGRATION' => 'true', 'PRIVATE_REPORT_WRITES' => 'true', 'MEDICAL_REPORT_PRODUCTION_ENABLED' => 'false', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $this->write();
    }

    private function write(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'stage.env', implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($this->values), array_values($this->values)))."\n");
        chmod($this->root.DIRECTORY_SEPARATOR.'stage.env', 0600);
    }

    private function target(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.'care360-staging'.DIRECTORY_SEPARATOR.'release_1';
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && str_contains($this->root, 'stage-package-')) {
            File::deleteDirectory($this->root);
        }parent::tearDown();
    }

    public function test_isolated_stage_validates_without_creating_or_overwriting_files(): void
    {
        $result = \MedicalReportStage::validate($this->target(), $this->root.DIRECTORY_SEPARATOR.'stage.env', $this->root.DIRECTORY_SEPARATOR.'live');
        $this->assertSame('staging', $result['APP_ENV']);
        $this->assertDirectoryDoesNotExist($this->target());
    }

    public function test_live_database_keys_host_or_activation_cannot_be_reused(): void
    {
        foreach ([['DB_DATABASE' => 'synthetic_live'], ['APP_KEY' => 'synthetic-live'], ['APP_URL' => 'https://live.example.org/other'], ['MEDICAL_REPORT_PRODUCTION_ENABLED' => 'true'], ['MAIL_MAILER' => 'smtp']] as $bad) {
            $old = $this->values;
            $this->values = array_merge($old, $bad);
            $this->write();
            try {
                \MedicalReportStage::validate($this->target(), $this->root.DIRECTORY_SEPARATOR.'stage.env', $this->root.DIRECTORY_SEPARATOR.'live');
                $this->fail('Unsafe isolation accepted');
            } catch (\RuntimeException $e) {
                $this->addToAssertionCount(1);
            }
            $this->values = $old;
        }
    }

    public function test_live_target_and_path_traversal_are_rejected(): void
    {
        foreach ([$this->root.DIRECTORY_SEPARATOR.'live'.DIRECTORY_SEPARATOR.'release', $this->root.DIRECTORY_SEPARATOR.'care360-staging'.DIRECTORY_SEPARATOR.'..'] as $target) {
            try {
                \MedicalReportStage::validate($target, $this->root.DIRECTORY_SEPARATOR.'stage.env', $this->root.DIRECTORY_SEPARATOR.'live');
                $this->fail('Unsafe target accepted');
            } catch (\RuntimeException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_manifest_path_traversal_fails_before_any_deployment(): void
    {
        $source = $this->root.DIRECTORY_SEPARATOR.'source';
        mkdir($source, 0700);
        file_put_contents($source.'/medical-release-manifest.json', json_encode(['files' => ['../escape.php' => str_repeat('a', 64)]]));
        try {
            \MedicalReportStage::install($source, $this->target(), $this->root.DIRECTORY_SEPARATOR.'stage.env', $this->root.DIRECTORY_SEPARATOR.'live');
            $this->fail('Manifest traversal accepted');
        } catch (\RuntimeException $e) {
            $this->assertDirectoryDoesNotExist($this->target());
        }
    }

    public function test_private_staging_backup_can_be_restored_into_a_separate_synthetic_database(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE fixture (id INTEGER PRIMARY KEY, body TEXT, absent TEXT)');
        $insert = $pdo->prepare('INSERT INTO fixture VALUES (1,?,NULL)');
        $insert->execute(["Synthetic quote ' and newline\n"]);
        $file = $this->root.DIRECTORY_SEPARATOR.'backup.sql';
        $method = new \ReflectionMethod(\MedicalReportStage::class, 'backup');
        $method->invoke(null, $pdo, $file);
        $restored = new \PDO('sqlite::memory:');
        $restored->exec(file_get_contents($file));
        $this->assertSame($pdo->query('SELECT * FROM fixture')->fetch(\PDO::FETCH_ASSOC), $restored->query('SELECT * FROM fixture')->fetch(\PDO::FETCH_ASSOC));
    }

    public function test_exported_production_environment_cannot_override_staging_commands(): void
    {
        $cwd = $this->root.DIRECTORY_SEPARATOR.'command-stage';
        File::makeDirectory($cwd.DIRECTORY_SEPARATOR.'storage/app/private/release-backups', 0700, true);
        copy($this->root.DIRECTORY_SEPARATOR.'stage.env', $cwd.DIRECTORY_SEPARATOR.'.env');
        chmod($cwd.DIRECTORY_SEPARATOR.'.env', 0600);
        $before = [];
        foreach (['APP_ENV', 'DB_DATABASE', 'MEDICAL_REPORT_PRODUCTION_ENABLED'] as $key) {
            $before[$key] = getenv($key);
        }
        putenv('APP_ENV=production');
        putenv('DB_DATABASE=must_never_use_this_live_database');
        putenv('MEDICAL_REPORT_PRODUCTION_ENABLED=true');
        try {
            $run = new \ReflectionMethod(\MedicalReportStage::class, 'run');
            $run->invoke(null, [PHP_BINARY, '-r', 'echo getenv("APP_ENV")."|".getenv("DB_DATABASE")."|".getenv("MEDICAL_REPORT_PRODUCTION_ENABLED");'], $cwd);
            $log = file_get_contents($cwd.'/storage/app/private/release-backups/commands.log');
            $this->assertSame('staging|synthetic_stage|false', $log);
        } finally {
            foreach ($before as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
        }
    }
}
