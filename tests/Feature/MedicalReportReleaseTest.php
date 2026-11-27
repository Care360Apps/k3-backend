<?php

namespace Tests\Feature;

use App\Services\MedicalReportLifecycle;
use App\Services\MedicalReportReleaseGate;
use App\Services\PrivateAppointmentReportStorage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MedicalReportReleaseTest extends TestCase
{
    private string $sandbox;

    private string $oldStorage;

    private string $oldPublic;

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldStorage = storage_path();
        $this->oldPublic = public_path();
        $this->sandbox = base_path('storage/framework/testing/reports-'.bin2hex(random_bytes(6)));
        foreach (['public/images/upload', 'storage/app', 'storage/framework', 'storage/logs'] as $d) {
            File::makeDirectory($this->sandbox.'/'.$d, 0700, true, true);
        }
        $this->app->useStoragePath($this->sandbox.'/storage');
        $this->app->usePublicPath($this->sandbox.'/public');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)), 'app.url' => 'https://staging.example.org', 'medical_files.allow_synthetic_migration' => true, 'medical_files.production_release_enabled' => false, 'medical_files.revocation_origins' => ['https://staging.example.org'], 'cache.default' => 'array']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('appointment', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->default(1);
            $t->integer('doctor_id')->default(2);
            $t->text('report_image')->nullable();
        });
        (require database_path('migrations/2026_11_27_030000_create_appointment_report_migration_ledger.php'))->up();
        (require database_path('migrations/2026_11_27_040000_create_report_file_operations.php'))->up();
        file_put_contents(storage_path('framework/down'), '{}');
    }

    protected function tearDown(): void
    {
        if (isset($this->oldStorage)) {
            $this->app->useStoragePath($this->oldStorage);
            $this->app->usePublicPath($this->oldPublic);
        }
        if (isset($this->sandbox) && str_starts_with($this->sandbox, base_path('storage/framework/testing/reports-'))) {
            File::deleteDirectory($this->sandbox);
        }
        parent::tearDown();
    }

    private function lifecycle(): MedicalReportLifecycle
    {
        return app(MedicalReportLifecycle::class);
    }

    private function fixture(string $name = 'synthetic-report.png'): string
    {
        file_put_contents(public_path('images/upload/'.$name), $this->png());
        DB::table('appointment')->insert(['report_image' => json_encode([$name])]);

        return $name;
    }

    public function test_migration_revocation_http_denial_and_private_recovery_round_trip(): void
    {
        $name = $this->fixture();
        $s = $this->lifecycle();
        $this->assertSame(1, $s->migrate());
        $this->assertSame(0, $s->migrate());
        $this->assertSame(1, $s->verify());
        $ledger = DB::table('appointment_report_migration_ledger')->first();
        $this->assertFileExists(public_path('images/upload/'.$name));
        $this->assertSame(1, $s->revoke());
        $this->assertSame(1, $s->revoke());
        $this->assertSame(1, $s->verify());
        $this->assertFileDoesNotExist(public_path('images/upload/'.$name));
        Http::fake(['https://staging.example.org/*' => Http::response('', 404)]);
        $this->assertSame(1, $s->verifyHttp());
        $this->assertNotNull(DB::table('medical_report_file_operations')->value('http_verified_at'));
        unlink(storage_path('app/private/medical/appointment-reports/'.$ledger->private_filename));
        $this->assertSame(1, $s->recover());
        $this->assertSame(1, $s->verify());
        $this->assertFileDoesNotExist(public_path('images/upload/'.$name));
        $this->assertGreaterThan(0, DB::table('medical_report_release_events')->count());
    }

    public function test_changed_original_fails_without_deleting_either_file(): void
    {
        $name = $this->fixture();
        $s = $this->lifecycle();
        $s->migrate();
        file_put_contents(public_path('images/upload/'.$name), 'changed');
        try {
            $s->revoke();
            $this->fail('Changed file revoked');
        } catch (\RuntimeException $e) {
            $this->assertFileExists(public_path('images/upload/'.$name));
        }
    }

    public function test_shared_upload_reference_blocks_revocation(): void
    {
        $name = $this->fixture();
        $s = $this->lifecycle();
        $s->migrate();
        Schema::create('unknown_shared_consumer', fn (Blueprint $t) => $t->text('payload'));
        DB::table('unknown_shared_consumer')->insert(['payload' => json_encode(['image' => $name])]);
        try {
            $s->revoke();
            $this->fail('Shared upload revoked');
        } catch (\RuntimeException $e) {
            $this->assertFileExists(public_path('images/upload/'.$name));
        }
    }

    public function test_redirect_or_public_cache_hit_never_counts_as_revocation(): void
    {
        $this->fixture();
        $s = $this->lifecycle();
        $s->migrate();
        $s->revoke();
        foreach ([200, 302, 500] as $status) {
            Http::fake(['*' => Http::response('cached', $status)]);
            try {
                $s->verifyHttp();
                $this->fail('Unsafe status accepted');
            } catch (\RuntimeException $e) {
                $this->assertNull(DB::table('medical_report_file_operations')->value('http_verified_at'));
            }
        }
    }

    public function test_empty_ledger_cannot_pass_http_acceptance(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->lifecycle()->verifyHttp();
    }

    public function test_orphans_require_two_aged_observations_and_recover_without_deletion(): void
    {
        $storage = app(PrivateAppointmentReportStorage::class);
        $name = $storage->store($this->png());
        touch(storage_path('app/private/medical/appointment-reports/'.$name), time() - 20 * 86400);
        $s = $this->lifecycle();
        $this->assertSame(1, $s->reconcile(true)['observed']);
        $this->assertSame(0, $s->reconcile(true)['quarantined']);
        DB::table('medical_report_file_operations')->update(['created_at' => now()->subDays(8)]);
        $this->assertSame(1, $s->reconcile(true)['quarantined']);
        $this->assertFileDoesNotExist(storage_path('app/private/medical/appointment-reports/'.$name));
        $this->assertSame(1, $s->recover());
        $this->assertFileExists(storage_path('app/private/medical/appointment-reports/'.$name));
    }

    public function test_referenced_and_ledger_backed_files_are_never_orphan_quarantined(): void
    {
        $this->fixture();
        $s = $this->lifecycle();
        $s->migrate();
        $row = DB::table('appointment_report_migration_ledger')->first();
        touch(storage_path('app/private/medical/appointment-reports/'.$row->private_filename), time() - 20 * 86400);
        DB::table('appointment')->delete();
        $this->assertSame(1, $s->reconcile(true)['referenced']);
    }

    public function test_failed_transaction_retains_original_and_reconciliation_finds_the_private_orphan(): void
    {
        $name = $this->fixture();
        Schema::drop('appointment_report_migration_ledger');
        try {
            $this->lifecycle()->migrate();
            $this->fail('Missing evidence schema accepted');
        } catch (\Throwable $e) {
            $this->assertFileExists(public_path('images/upload/'.$name));
        }
        $this->assertSame(json_encode([$name]), DB::table('appointment')->value('report_image'));
    }

    public function test_maintenance_and_explicit_production_approval_are_required(): void
    {
        unlink(storage_path('framework/down'));
        try {
            $this->lifecycle()->migrate();
            $this->fail('Online migration accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Maintenance', $e->getMessage());
        }
        file_put_contents(storage_path('framework/down'), '{}');
        $this->app->instance('env', 'production');
        try {
            $this->lifecycle()->migrate();
            $this->fail('Production enabled');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }
        config(['medical_files.private_report_writes' => true, 'medical_files.production_release_enabled' => true]);
        $this->expectException(\RuntimeException::class);
        $this->lifecycle()->migrate();
    }

    public function test_bound_operator_approval_expires_and_cannot_target_another_database(): void
    {
        $gate = app(MedicalReportReleaseGate::class);
        $file = storage_path('app/approval.json');
        config(['medical_files.private_report_writes' => true, 'medical_files.production_release_enabled' => true, 'medical_files.release_approval_file' => $file, 'medical_files.staging_acceptance_sha256' => str_repeat('a', 64), 'medical_files.deployment_id' => 'synthetic-commit']);
        $approval = ['approved' => true, 'backup_restore_verified' => true, 'writers_stopped' => true, 'staging_acceptance_sha256' => str_repeat('a', 64), 'target_sha256' => $gate->targetFingerprint(), 'deployment_id' => 'synthetic-commit', 'expires_at' => now()->addHour()->toIso8601String(), 'actions' => ['migrate']];
        file_put_contents($file, json_encode($approval));
        chmod($file, 0600);
        $this->app->instance('env', 'production');
        $gate->authorize('migrate');
        $this->addToAssertionCount(1);
        $approval['target_sha256'] = str_repeat('b', 64);
        file_put_contents($file, json_encode($approval));
        $this->expectException(\RuntimeException::class);
        $gate->authorize('migrate');
    }

    public function test_private_download_authorization_after_revocation(): void
    {
        $this->fixture();
        $this->lifecycle()->migrate();
        $this->lifecycle()->revoke();
        $row = DB::table('appointment_report_migration_ledger')->first();
        unlink(storage_path('framework/down'));
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('status')->default(1);
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->integer('role_id');
            $t->string('model_type');
            $t->integer('model_id');
        });
        Schema::create('doctor', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');
            $t->boolean('is_approved');
            $t->boolean('status');
        });
        foreach ([1, 2, 3, 4] as $id) {
            DB::table('users')->insert(['id' => $id, 'name' => 'Synthetic account', 'status' => 1]);
        }
        DB::table('roles')->insert(['id' => 1, 'name' => 'doctor', 'guard_name' => 'api']);
        foreach ([2, 4] as $id) {
            DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => \App\Models\User::class, 'model_id' => $id]);
        }
        DB::table('doctor')->insert([['id' => 2, 'user_id' => 2, 'is_approved' => 1, 'status' => 1], ['id' => 4, 'user_id' => 4, 'is_approved' => 1, 'status' => 1]]);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        config(['passport.public_key' => openssl_pkey_get_details($key)['key']]);
        $url = '/api/medical-files/appointments/1/reports/'.$row->private_filename;
        $this->getJson($url)->assertUnauthorized();
        foreach ([1, 2] as $id) {
            \Laravel\Passport\Passport::actingAs(\App\Models\User::find($id));
            $response = $this->get($url);
            $response->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        foreach ([3, 4] as $id) {
            \Laravel\Passport\Passport::actingAs(\App\Models\User::find($id));
            $this->getJson($url)->assertNotFound();
        }
        DB::table('doctor')->where('id', 2)->update(['is_approved' => 0]);
        \Laravel\Passport\Passport::actingAs(\App\Models\User::find(2));
        $this->getJson($url)->assertNotFound();
        DB::table('users')->where('id', 1)->update(['status' => 0]);
        \Laravel\Passport\Passport::actingAs(\App\Models\User::find(1));
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_orphan_reconciliation_resumes_after_filesystem_move_before_db_commit(): void
    {
        $name = app(PrivateAppointmentReportStorage::class)->store($this->png());
        $file = storage_path('app/private/medical/appointment-reports/'.$name);
        touch($file, time() - 20 * 86400);
        $s = $this->lifecycle();
        $s->reconcile();
        $target = storage_path('app/private/medical/report-quarantine/'.$name);
        rename($file, $target);
        $s->reconcile();
        $this->assertSame('quarantined', DB::table('medical_report_file_operations')->value('state'));
        $this->assertSame(1, $s->recover());
    }

    public function test_expired_approval_and_unapproved_actions_are_rejected(): void
    {
        $gate = app(MedicalReportReleaseGate::class);
        $file = storage_path('app/approval.json');
        config(['medical_files.private_report_writes' => true, 'medical_files.production_release_enabled' => true, 'medical_files.release_approval_file' => $file, 'medical_files.staging_acceptance_sha256' => str_repeat('a', 64), 'medical_files.deployment_id' => 'synthetic']);
        $approval = ['approved' => true, 'backup_restore_verified' => true, 'writers_stopped' => true, 'staging_acceptance_sha256' => str_repeat('a', 64), 'target_sha256' => $gate->targetFingerprint(), 'deployment_id' => 'synthetic', 'expires_at' => now()->subHour()->toIso8601String(), 'actions' => ['migrate']];
        file_put_contents($file, json_encode($approval));
        chmod($file, 0600);
        $this->app->instance('env', 'production');
        try {
            $gate->authorize('migrate');
            $this->fail('Expired approval accepted');
        } catch (\RuntimeException $e) {
            $this->addToAssertionCount(1);
        }
        $approval['expires_at'] = now()->addHour()->toIso8601String();
        file_put_contents($file, json_encode($approval));
        $this->expectException(\RuntimeException::class);
        $gate->authorize('activate', false);
    }

    public function test_storage_lock_excludes_a_second_php_writer_process(): void
    {
        $autoload = base_path('vendor/autoload.php');
        $bootstrap = base_path('bootstrap/app.php');
        $paths = base64_encode(json_encode([$autoload, $bootstrap, storage_path(), public_path()]));
        $code = '$p=json_decode(base64_decode("'.$paths.'"),true);require $p[0];$app=require $p[1];$app->useStoragePath($p[2]);$app->usePublicPath($p[3]);try{App\\Services\\ReportStorageLock::run(fn()=>1);exit(0);}catch(Throwable $e){exit(23);}';
        $run = function () use ($code) {
            $proc = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

return proc_close($proc);
        };
        $this->assertSame(23, \App\Services\ReportStorageLock::run($run));
        $this->assertSame(0, $run());
    }

    public function test_changed_orphan_is_retained_and_not_quarantined(): void
    {
        $name = app(PrivateAppointmentReportStorage::class)->store($this->png());
        $file = storage_path('app/private/medical/appointment-reports/'.$name);
        touch($file, time() - 20 * 86400);
        $this->lifecycle()->reconcile();
        DB::table('medical_report_file_operations')->update(['created_at' => now()->subDays(8)]);
        file_put_contents($file,'changed');
        touch($file,time() - 20 * 86400);
        try {
            $this->lifecycle()->reconcile(true);
            $this->fail('Changed orphan moved');
        } catch (\RuntimeException $e) {
            $this->assertFileExists($file);
        }
    }
}
