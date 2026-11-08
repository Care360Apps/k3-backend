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
        });
        Schema::create('doctor', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
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
        DB::table('doctor')->insert([
            ['id' => 201, 'user_id' => 102],
            ['id' => 202, 'user_id' => 104],
        ]);
        DB::table('appointment')->insert([
            'id' => 301,
            'user_id' => 101,
            'doctor_id' => 201,
        ]);

        $this->filename = bin2hex(random_bytes(20)).'.pdf';
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
}
