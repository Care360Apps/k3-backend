<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class PrivatePrescriptionAccessTest extends TestCase
{
    private string $filename;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing')) {
            $this->markTestSkipped('Private clinical authorization requires a test-only environment.');
        }

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('status')->default(true);
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('doctor', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('is_approved')->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->string('user_role')->nullable();
        });
        Schema::create('appointment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('doctor_id');
        });
        Schema::create('prescription', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('user_id');
            $table->string('pdf');
        });

        DB::table('users')->insert([
            ['id' => 101, 'name' => 'Synthetic Patient A', 'email' => 'a@example.test'],
            ['id' => 102, 'name' => 'Synthetic Assigned Doctor', 'email' => 'doctor@example.test'],
            ['id' => 103, 'name' => 'Synthetic Stranger', 'email' => 'stranger@example.test'],
            ['id' => 104, 'name' => 'Synthetic Other Doctor', 'email' => 'other@example.test'],
        ]);
        DB::table('roles')->insert([
            'id' => 1, 'name' => 'doctor', 'guard_name' => 'api',
        ]);
        DB::table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => 102],
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => 104],
        ]);
        DB::table('doctor')->insert([
            ['id' => 201, 'user_id' => 102, 'is_approved' => 1, 'status' => 1, 'user_role' => 'doctor'],
            ['id' => 202, 'user_id' => 104, 'is_approved' => 1, 'status' => 1, 'user_role' => 'doctor'],
        ]);
        DB::table('appointment')->insert([
            'id' => 301,
            'user_id' => 101,
            'doctor_id' => 201,
        ]);

        $this->filename = 'k3priv_'.bin2hex(random_bytes(20)).'.pdf';
        DB::table('prescription')->insert([
            'id' => 401,
            'appointment_id' => 301,
            'user_id' => 101,
            'pdf' => $this->filename,
        ]);

        $folder = storage_path('app/private/medical/prescriptions');
        if (! is_dir($folder)) {
            mkdir($folder, 0700, true);
        }
        $this->path = $folder.DIRECTORY_SEPARATOR.$this->filename;
        file_put_contents($this->path, "%PDF-1.4\nSynthetic test document\n%%EOF\n");
        chmod($this->path, 0600);
    }

    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_patient_and_assigned_doctor_can_download_but_strangers_cannot(): void
    {
        $uri = '/api/medical-files/appointments/301/prescription';

        $this->actingAs(User::findOrFail(101), 'api')->get($uri)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs(User::findOrFail(102), 'api')->get($uri)->assertOk();
        $this->actingAs(User::findOrFail(103), 'api')->get($uri)->assertNotFound();
        $this->actingAs(User::findOrFail(104), 'api')->get($uri)->assertNotFound();
    }

    public function test_wrong_appointment_and_missing_private_file_fail_closed(): void
    {
        $uri = '/api/medical-files/appointments/301/prescription';
        $this->actingAs(User::findOrFail(101), 'api')
            ->get('/api/medical-files/appointments/999/prescription')
            ->assertNotFound();

        unlink($this->path);
        $this->get($uri)->assertNotFound();
    }

    public function test_prescription_must_match_appointment_owner(): void
    {
        DB::table('prescription')->where('id', 401)->update(['user_id' => 103]);
        $this->actingAs(User::findOrFail(101), 'api')
            ->get('/api/medical-files/appointments/301/prescription')
            ->assertNotFound();
    }
    public function test_suspended_doctor_cannot_download_but_patient_still_can(): void
    {
        $url = '/api/medical-files/appointments/301/prescription';
        $this->actingAs(User::findOrFail(102), 'api')->get($url)->assertOk();

        DB::table('users')->where('id', 102)->update(['status' => 0]);
        $this->actingAs(User::findOrFail(102), 'api')->get($url)->assertNotFound();

        // A clinician's suspension must not silently revoke patient access.
        $this->actingAs(User::findOrFail(101), 'api')->get($url)->assertOk();
    }

    public function test_arbitrary_pdf_inside_private_directory_is_not_downloadable(): void
    {
        // A stored filename must have private-writer provenance, not merely
        // look like a safe .pdf basename. This synthetic file exists under
        // the protected directory to distinguish naming from missing-file 404.
        $untrustedName = 'external_'.bin2hex(random_bytes(20)).'.pdf';
        $otherPath = dirname($this->path).DIRECTORY_SEPARATOR.$untrustedName;
        file_put_contents($otherPath, "%PDF-1.4\nSynthetic unrelated document\n%%EOF\n");
        try {
            DB::table('prescription')->where('id', 401)
                ->update(['pdf' => $untrustedName]);
            $this->actingAs(User::findOrFail(101), 'api')
                ->get('/api/medical-files/appointments/301/prescription')
                ->assertNotFound();
        } finally {
            if (is_file($otherPath)) {
                unlink($otherPath);
            }
        }
    }

    public function test_revoked_or_disabled_assigned_professional_loses_access(): void
    {
        $url = '/api/medical-files/appointments/301/prescription';
        $this->actingAs(User::findOrFail(102), 'api')->get($url)->assertOk();

        DB::table('doctor')->where('id', 201)->update(['is_approved' => 0]);
        $this->get($url)->assertNotFound();

        DB::table('doctor')->where('id', 201)
            ->update(['is_approved' => 1, 'status' => 0]);
        $this->get($url)->assertNotFound();

        DB::table('doctor')->where('id', 201)
            ->update(['status' => 1, 'user_role' => 'provider']);
        $this->get($url)->assertNotFound();

        DB::table('doctor')->where('id', 201)->update(['user_role' => 'doctor']);
        DB::table('model_has_roles')->where('model_id', 102)->delete();
        $this->actingAs(User::findOrFail(102), 'api')->get($url)->assertNotFound();
    }

}
