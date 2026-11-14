<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->string('account_type', 20); // candidate or employer
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('workforce_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('workforce_accounts')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'expires_at']);
        });
        Schema::create('workforce_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('workforce_accounts')->cascadeOnDelete();
            $table->string('career_stage', 30)->default('student');
            $table->string('institution', 150)->nullable();
            $table->string('qualification', 150)->nullable();
            $table->string('desired_role', 100)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('shift_preference', 32)->nullable();
            $table->string('verification_status', 24)->default('pending');
            $table->string('licence_status', 24)->default('not_verified');
            $table->date('licence_expires_at')->nullable();
            $table->boolean('available_for_work')->default(false);
            $table->boolean('consent_to_list')->default(false);
            $table->timestamps();
            $table->index(['verification_status', 'available_for_work', 'consent_to_list'], 'workforce_candidate_listing_idx');
        });
        Schema::create('workforce_employers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('workforce_accounts')->cascadeOnDelete();
            $table->string('organization_name', 150);
            $table->string('organization_type', 50);
            $table->string('registration_number', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('verification_status', 24)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_employers');
        Schema::dropIfExists('workforce_candidates');
        Schema::dropIfExists('workforce_sessions');
        Schema::dropIfExists('workforce_accounts');
    }
};
