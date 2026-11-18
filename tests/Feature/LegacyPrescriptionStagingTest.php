<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyPrescriptionStagingTest extends TestCase
{
    private string $legacy;
    private string $publicFile;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing')) {
            $this->markTestSkipped('Medical-file migration tests must use testing only.');
        }
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        config()->set('medical_files.allow_synthetic_migration', true);

        Schema::create('prescription', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id');
            $table->string('pdf')->nullable();
        });
        Schema::create('medical_prescription_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prescription_id')->unique();
            $table->string('legacy_filename');
            $table->string('private_filename');
            $table->char('sha256', 64);
            $table->string('state', 20)->default('staged');
            $table->timestamps();
        });

        $this->legacy = bin2hex(random_bytes(16)).'.pdf';
        $dir = public_path('prescription/upload');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->publicFile = $dir.DIRECTORY_SEPARATOR.$this->legacy;
        file_put_contents($this->publicFile, "%PDF-1.4\nSynthetic record only\n%%EOF\n");
        DB::table('prescription')->insert([
            'id' => 801, 'appointment_id' => 701, 'pdf' => $this->legacy,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->publicFile) && is_file($this->publicFile)) {
            unlink($this->publicFile);
        }
        if (Schema::hasTable('medical_prescription_migrations')) {
            $mappings = DB::table('medical_prescription_migrations')->get();
            foreach ($mappings as $mapping) {
                $name = (string) $mapping->private_filename;
                if (preg_match('/\Ak3priv_[a-f0-9]{40}\.pdf\z/D', $name)) {
                    $file = storage_path('app/private/medical/prescriptions/'.$name);
                    if (is_file($file) && ! is_link($file)) {
                        unlink($file);
                    }
                }
            }
        }
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_dry_run_never_changes_records_or_originals(): void
    {
        $this->assertSame(0, Artisan::call('medical-files:stage-prescriptions'));
        $this->assertSame($this->legacy, DB::table('prescription')->value('pdf'));
        $this->assertSame(0, DB::table('medical_prescription_migrations')->count());
        $this->assertFileExists($this->publicFile);
    }

    public function test_migration_requires_explicit_synthetic_approval(): void
    {
        $this->assertSame(1, Artisan::call('medical-files:stage-prescriptions', [
            '--apply' => true,
        ]));
        config()->set('medical_files.allow_synthetic_migration', false);
        $this->assertSame(1, Artisan::call('medical-files:stage-prescriptions', [
            '--apply' => true, '--confirm-synthetic-staging' => true,
        ]));
        $this->assertSame($this->legacy, DB::table('prescription')->value('pdf'));
    }

    public function test_copy_update_and_rollback_preserve_original_and_private_copy(): void
    {
        $arguments = ['--apply' => true, '--confirm-synthetic-staging' => true];
        $this->assertSame(0, Artisan::call('medical-files:stage-prescriptions', $arguments));

        $new = (string) DB::table('prescription')->value('pdf');
        $this->assertMatchesRegularExpression('/^k3priv_[a-f0-9]{40}\.pdf$/D', $new);
        $private = storage_path('app/private/medical/prescriptions/'.$new);
        $this->assertFileExists($private);
        $this->assertSame(hash_file('sha256', $this->publicFile), hash_file('sha256', $private));
        $this->assertFileExists($this->publicFile);
        $this->assertSame('staged', DB::table('medical_prescription_migrations')->value('state'));

        $this->assertSame(0, Artisan::call('medical-files:stage-prescriptions',
            array_merge($arguments, ['--rollback' => true])));
        $this->assertSame($this->legacy, DB::table('prescription')->value('pdf'));
        $this->assertSame('rolled_back', DB::table('medical_prescription_migrations')->value('state'));
        $this->assertFileExists($this->publicFile);
        $this->assertFileExists($private);
    }

    public function test_missing_or_bad_pdf_is_skipped_without_record_mutation(): void
    {
        file_put_contents($this->publicFile, 'synthetic non-PDF content');
        $this->assertSame(1, Artisan::call('medical-files:stage-prescriptions', [
            '--apply' => true, '--confirm-synthetic-staging' => true,
        ]));
        $this->assertSame($this->legacy, DB::table('prescription')->value('pdf'));
        $this->assertSame(0, DB::table('medical_prescription_migrations')->count());
    }
}
