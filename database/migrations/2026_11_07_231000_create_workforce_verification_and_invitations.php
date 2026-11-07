<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_verification_events', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 24); // candidate or employer
            $table->unsignedBigInteger('subject_id');
            $table->string('reviewer_reference', 100);
            $table->string('decision', 24);
            $table->char('evidence_sha256', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
        Schema::create('workforce_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_account_id')
                ->constrained('workforce_accounts')->cascadeOnDelete();
            $table->foreignId('candidate_account_id')
                ->constrained('workforce_accounts')->cascadeOnDelete();
            $table->string('role_title', 100);
            $table->text('job_summary'); // Do not include patient identifiers.
            $table->string('status', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['candidate_account_id', 'status']);
            $table->index(['employer_account_id', 'status']);
        });
        Schema::create('workforce_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')
                ->constrained('workforce_invitations')->cascadeOnDelete();
            $table->foreignId('sender_account_id')
                ->constrained('workforce_accounts')->cascadeOnDelete();
            $table->text('body_encrypted');
            $table->timestamps();
            $table->index(['invitation_id', 'id']);
        });
        Schema::create('workforce_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')
                ->constrained('workforce_invitations')->cascadeOnDelete();
            $table->foreignId('reporter_account_id')
                ->constrained('workforce_accounts')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['invitation_id', 'reporter_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_reports');
        Schema::dropIfExists('workforce_messages');
        Schema::dropIfExists('workforce_invitations');
        Schema::dropIfExists('workforce_verification_events');
    }
};
