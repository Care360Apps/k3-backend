<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureApprovedProfessional;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApprovedProfessionalAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing')) {
            $this->markTestSkipped('Professional authorization requires synthetic-only testing.');
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
            $table->string('user_role')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->boolean('status')->default(false);
        });
        DB::table('users')->insert([
            ['id' => 101, 'name' => 'Approved Doctor', 'email' => 'doc@example.test'],
            ['id' => 102, 'name' => 'Revoked Doctor', 'email' => 'revoked@example.test'],
            ['id' => 103, 'name' => 'Approved Provider', 'email' => 'provider@example.test'],
            ['id' => 104, 'name' => 'Patient', 'email' => 'patient@example.test'],
            ['id' => 105, 'name' => 'Unapproved Provider', 'email' => 'pending@example.test'],
            ['id' => 106, 'name' => 'Legacy Doctor', 'email' => 'legacy@example.test'],
        ]);
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'doctor', 'guard_name' => 'api'],
            ['id' => 2, 'name' => 'provider', 'guard_name' => 'api'],
        ]);
        DB::table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => 101],
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => 102],
            ['role_id' => 2, 'model_type' => User::class, 'model_id' => 103],
            ['role_id' => 2, 'model_type' => User::class, 'model_id' => 105],
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => 106],
        ]);
        DB::table('doctor')->insert([
            ['id' => 201, 'user_id' => 101, 'user_role' => 'doctor', 'is_approved' => 1, 'status' => 1],
            ['id' => 202, 'user_id' => 102, 'user_role' => 'doctor', 'is_approved' => 0, 'status' => 1],
            ['id' => 203, 'user_id' => 103, 'user_role' => 'provider', 'is_approved' => 1, 'status' => 1],
            ['id' => 205, 'user_id' => 105, 'user_role' => 'provider', 'is_approved' => 0, 'status' => 1],
            ['id' => 206, 'user_id' => 106, 'user_role' => null, 'is_approved' => 1, 'status' => 1],
        ]);
        Route::middleware([EnsureApprovedProfessional::class])->get(
            '/__synthetic_professional_guard',
            fn () => response()->json(['allowed' => true])
        );
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_approved_doctor_and_provider_can_enter_guarded_route(): void
    {
        $this->actingAs(User::findOrFail(101), 'api')
            ->get('/__synthetic_professional_guard')->assertOk();
        $this->actingAs(User::findOrFail(103), 'api')
            ->get('/__synthetic_professional_guard')->assertOk();
        $this->actingAs(User::findOrFail(106), 'api')
            ->get('/__synthetic_professional_guard')->assertOk();
    }

    public function test_revoked_unapproved_and_patient_roles_are_rejected(): void
    {
        foreach ([102, 104, 105] as $userId) {
            $this->actingAs(User::findOrFail($userId), 'api')
                ->get('/__synthetic_professional_guard')->assertForbidden();
        }
        DB::table('doctor')->where('id', 203)->update(['status' => 0]);
        $this->actingAs(User::findOrFail(103), 'api')
            ->get('/__synthetic_professional_guard')->assertForbidden();
    }

    public function test_suspended_professional_user_is_rejected_even_with_existing_auth(): void
    {
        $doctor = User::findOrFail(101);
        $this->actingAs($doctor, 'api')
            ->get('/__synthetic_professional_guard')->assertOk();

        DB::table('users')->where('id', 101)->update(['status' => 0]);
        $this->actingAs(User::findOrFail(101), 'api')
            ->get('/__synthetic_professional_guard')->assertForbidden();

        DB::table('users')->where('id', 103)->update(['status' => 0]);
        $this->actingAs(User::findOrFail(103), 'api')
            ->get('/__synthetic_professional_guard')->assertForbidden();
    }

    public function test_role_mismatch_fails_closed(): void
    {
        DB::table('doctor')->where('id', 203)->update(['user_role' => 'doctor']);
        $this->actingAs(User::findOrFail(103), 'api')
            ->get('/__synthetic_professional_guard')->assertForbidden();
    }
}
