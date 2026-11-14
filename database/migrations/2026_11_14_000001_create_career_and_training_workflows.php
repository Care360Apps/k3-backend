<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('career_password_resets')) {
            Schema::create('career_password_resets', function (Blueprint $t) {
                $t->id();
                $t->foreignId('account_id')->constrained('workforce_accounts');
                $t->char('token_hash', 64)->unique();
                $t->timestamp('expires_at');
                $t->timestamp('redeemed_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('career_privacy_requests')) {
            Schema::create('career_privacy_requests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('account_id')->constrained('workforce_accounts');
                $t->string('status', 24)->default('requested');
                $t->longText('details');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('career_portfolios')) {
            Schema::create('career_portfolios', function (Blueprint $t) {
                $t->id();
                $t->foreignId('account_id')->unique()->constrained('workforce_accounts');
                $t->longText('payload');
                $t->unsignedTinyInteger('experience_years')->default(0);
                $t->boolean('share_training')->default(false);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('career_documents')) {
            Schema::create('career_documents', function (Blueprint $t) {
                $t->id();
                $t->foreignId('account_id')->constrained('workforce_accounts');
                $t->string('kind', 32);
                $t->string('storage_name', 80)->unique();
                $t->string('mime', 80);
                $t->unsignedInteger('bytes');
                $t->char('sha256', 64);
                $t->string('review_status', 24)->default('pending');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('career_recruiters')) {
            Schema::create('career_recruiters', function (Blueprint $t) {
                $t->id();
                $t->foreignId('owner_account_id')->constrained('workforce_accounts');
                $t->foreignId('recruiter_account_id')->unique()->constrained('workforce_accounts');
                $t->string('status', 24)->default('pending_review');
                $t->char('evidence_sha256', 64)->nullable();
                $t->string('reviewer', 80)->nullable();
                $t->timestamp('accepted_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('career_jobs')) {
            Schema::create('career_jobs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employer_account_id')->constrained('workforce_accounts');
                $t->string('title', 150);
                $t->string('profession', 100);
                $t->string('state', 80);
                $t->string('employment_type', 30);
                $t->text('description');
                $t->text('requirements');
                $t->date('closes_on');
                $t->string('status', 24)->default('pending');
                $t->timestamps();
                $t->index(['status', 'closes_on']);
            });
        }
        if (! Schema::hasColumn('workforce_invitations', 'job_id')) {
            Schema::table('workforce_invitations', function (Blueprint $t) {
                $t->unsignedBigInteger('job_id')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('workforce_reports', 'status')) {
            Schema::table('workforce_reports', function (Blueprint $t) {
                $t->string('status', 24)->default('open');
            });
        }
        if (! Schema::hasTable('career_applications')) {
            Schema::create('career_applications', function (Blueprint $t) {
                $t->id();
                $t->foreignId('job_id')->constrained('career_jobs');
                $t->foreignId('candidate_account_id')->constrained('workforce_accounts');
                $t->longText('payload');
                $t->unsignedBigInteger('cv_document_id')->nullable();
                $t->string('status', 24)->default('submitted');
                $t->timestamps();
                $t->unique(['job_id', 'candidate_account_id'], 'career_application_once');
            });
        }
        if (! Schema::hasTable('career_interviews')) {
            Schema::create('career_interviews', function (Blueprint $t) {
                $t->id();
                $t->foreignId('application_id')->constrained('career_applications');
                $t->dateTime('scheduled_at');
                $t->string('timezone', 80);
                $t->longText('details');
                $t->string('candidate_response', 24)->default('pending');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('career_notifications')) {
            Schema::create('career_notifications', function (Blueprint $t) {
                $t->id();
                $t->foreignId('account_id')->constrained('workforce_accounts');
                $t->string('kind', 40);
                $t->unsignedBigInteger('resource_id');
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('career_workflow_events')) {
            Schema::create('career_workflow_events', function (Blueprint $t) {
                $t->id();
                $t->string('subject_type', 40);
                $t->unsignedBigInteger('subject_id');
                $t->string('actor', 80);
                $t->string('action', 50);
                $t->longText('details');
                $t->timestamp('created_at');
                $t->index(['subject_type', 'subject_id']);
            });
        }
        if (! Schema::hasTable('training_courses')) {
            Schema::create('training_courses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('legacy_course_id')->nullable()->index();
                $t->string('title', 150);
                $t->string('category', 80);
                $t->text('description');
                $t->text('eligibility');
                $t->string('delivery', 24);
                $t->string('duration', 100);
                $t->string('fee_information', 200);
                $t->string('website', 500)->nullable();
                $t->dateTime('starts_at')->nullable();
                $t->unsignedInteger('capacity')->nullable();
                $t->boolean('published')->default(false);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('training_enrollments')) {
            Schema::create('training_enrollments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('course_id')->constrained('training_courses');
                $t->foreignId('account_id')->constrained('workforce_accounts');
                $t->string('status', 24)->default('requested');
                $t->unsignedTinyInteger('progress')->default(0);
                $t->longText('details');
                $t->unsignedBigInteger('certificate_document_id')->nullable();
                $t->char('completion_evidence_sha256', 64)->nullable();
                $t->timestamps();
                $t->unique(['course_id', 'account_id'], 'training_enrollment_once');
            });
        }
        if (! Schema::hasTable('training_organization_requests')) {
            Schema::create('training_organization_requests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('account_id')->constrained('workforce_accounts');
                $t->string('topic', 150);
                $t->unsignedInteger('participants');
                $t->string('delivery', 24);
                $t->date('preferred_date');
                $t->longText('details');
                $t->string('status', 24)->default('submitted');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Deliberately retain recruitment/training records. Rollback application
        // code and disable flags; destruction requires a reviewed retention plan.
        throw new \RuntimeException('Career/training data must not be discarded by migration rollback.');
    }
};
