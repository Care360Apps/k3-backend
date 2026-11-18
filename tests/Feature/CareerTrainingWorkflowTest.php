<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CareerDocumentStorage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CareerTrainingWorkflowTest extends TestCase
{
    use \Tests\Support\SeedsWebLocalization;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.limiter', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing')) {
            $this->markTestSkipped('Synthetic testing only.');
        }
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        config()->set('cache.default', 'array');
        config()->set('cache.limiter', 'array');
        config()->set('session.driver', 'array');
        config()->set('workforce.enabled', true);
        config()->set('workforce.notice_approved', true);
        config()->set('workforce.notice_url', 'https://care360apps.com/recruitment-privacy');
        config()->set('workforce.notice_version', 'synthetic-v1');
        config()->set('workforce.local_demo', false);
        config()->set('career.enabled', true);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        foreach (['2026_11_07_230000_create_workforce_pilot_tables.php', '2026_11_07_231000_create_workforce_verification_and_invitations.php',
            '2026_11_14_000000_add_workforce_notice_consent.php', '2026_11_14_000001_create_career_and_training_workflows.php'] as $m) {
            (require database_path('migrations/'.$m))->up();
        }
        $this->seedWebLocalization();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->boolean('status')->default(1);
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
        });
        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->integer('role_id');
            $t->string('model_type');
            $t->integer('model_id');
        });
        Schema::create('model_has_permissions', function (Blueprint $t) {
            $t->integer('permission_id');
            $t->string('model_type');
            $t->integer('model_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $t) {
            $t->integer('permission_id');
            $t->integer('role_id');
        });
        DB::table('users')->insert([['id' => 900, 'name' => 'Synthetic reviewer', 'email' => 'reviewer@care360.example.test'],
            ['id' => 901, 'name' => 'Unprivileged user', 'email' => 'other@care360.example.test']]);
        DB::table('roles')->insert(['id' => 1, 'name' => 'super admin', 'guard_name' => 'web']);
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => User::class, 'model_id' => 900]);
        Gate::define('training_manage', fn ($u) => $u->id === 900);
        Gate::define('workforce_review', fn ($u) => $u->id === 900);
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('career_documents')) {
            foreach (DB::table('career_documents')->get() as $doc) {
                (new CareerDocumentStorage)->remove($doc->storage_name);
            }
        }
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function account(string $type, string $email): array
    {
        $this->postJson('/api/workforce/v1/register', ['name' => 'Synthetic '.$type, 'email' => $email,
            'password' => 'Synthetic-Only-Password-2026!', 'password_confirmation' => 'Synthetic-Only-Password-2026!',
            'account_type' => $type, 'adult_attestation' => true, 'workforce_terms_accepted' => true, 'notice_version' => 'synthetic-v1'])->assertCreated();
        $login = $this->postJson('/api/workforce/v1/login', ['email' => $email, 'password' => 'Synthetic-Only-Password-2026!'])->assertOk();

        return ['id' => DB::table('workforce_accounts')->where('email', $email)->value('id'),
            'headers' => ['Authorization' => 'Bearer '.$login->json('data.token')]];
    }

    private function candidate(string $suffix = 'one'): array
    {
        $a = $this->account('candidate', $suffix.'@care360.example.test');
        $this->postJson('/api/workforce/v1/candidate/profile', ['career_stage' => 'graduate', 'institution' => 'Synthetic College',
            'qualification' => 'Care support certificate', 'desired_role' => 'care_assistant', 'state' => 'Lagos', 'city' => 'Ikeja',
            'shift_preference' => 'flexible', 'available_for_work' => true, 'consent_to_list' => true], $a['headers'])->assertOk();
        $this->admin()->post('/workforce/review', ['kind' => 'candidate', 'profile_id' => DB::table('workforce_candidates')->where('account_id', $a['id'])->value('id'),
            'decision' => 'verified', 'evidence_sha256' => str_repeat('a', 64), 'email_checked' => 1])->assertRedirect();

        return $a;
    }

    private function employer(string $suffix = 'organization'): array
    {
        $a = $this->account('employer', $suffix.'@care360.example.test');
        $this->postJson('/api/workforce/v1/employer/profile', ['organization_name' => 'Synthetic Clinic', 'organization_type' => 'clinic',
            'registration_number' => 'SYNTHETIC-12345', 'state' => 'Lagos'], $a['headers'])->assertOk();
        $this->admin()->post('/workforce/review', ['kind' => 'employer', 'profile_id' => DB::table('workforce_employers')->where('account_id', $a['id'])->value('id'),
            'decision' => 'verified', 'evidence_sha256' => str_repeat('b', 64), 'email_checked' => 1])->assertRedirect();

        return $a;
    }

    private function admin(): self
    {
        return $this->actingAs(User::findOrFail(900), 'web');
    }

    private function job(array $employer): int
    {
        $j = $this->postJson('/api/workforce/v1/jobs', ['title' => 'Synthetic care assistant', 'profession' => 'care_assistant', 'state' => 'Lagos',
            'employment_type' => 'full_time', 'description' => 'Fictional healthcare support vacancy for testing.',
            'requirements' => 'Verified care support qualification.', 'closes_on' => today()->addDays(30)->toDateString()], $employer['headers'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->admin()->post('/career/manage/jobs/'.$j, ['status' => 'published', 'note' => 'Synthetic moderation complete'])->assertRedirect();

        return $j;
    }

    private function portfolio(array $a, bool $share = false): void
    {
        $this->postJson('/api/workforce/v1/portfolio', ['education' => [['institution' => 'Synthetic College', 'qualification' => 'Care Support', 'year' => 2025]],
            'experience' => [['organization' => 'Synthetic Centre', 'role' => 'Care support', 'years' => 1]], 'skills' => ['Communication'],
            'preferred_locations' => ['Lagos'], 'employment_preferences' => 'Full time', 'share_training' => $share], $a['headers'])->assertOk();
    }

    public function test_candidate_employer_recruitment_journey_and_offer_acceptance(): void
    {
        $candidate = $this->candidate();
        $employer = $this->employer();
        $this->portfolio($candidate);
        $job = $this->job($employer);
        $this->getJson('/api/workforce/v1/candidates?qualification=Care&min_experience_years=1', $employer['headers'])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/workforce/v1/jobs', $candidate['headers'])->assertOk()->assertJsonPath('data.data.0.id', $job);
        $this->getJson('/api/workforce/v1/candidates', $employer['headers'])->assertOk()->assertJsonMissing(['email' => 'one@care360.example.test']);
        $inv = $this->postJson('/api/workforce/v1/invitations', ['job_id' => $job, 'candidate_id' => DB::table('workforce_candidates')->where('account_id', $candidate['id'])->value('id')], $employer['headers'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/workforce/v1/invitations/'.$inv.'/response', ['status' => 'accepted'], $candidate['headers'])->assertOk();
        $this->postJson('/api/workforce/v1/invitations/'.$inv.'/messages', ['body' => 'Synthetic interview discussion only.'], $employer['headers'])->assertCreated();
        $this->assertStringNotContainsString('Synthetic interview', DB::table('workforce_messages')->first()->body_encrypted);
        $this->getJson('/api/workforce/v1/invitations/'.$inv.'/messages', $candidate['headers'])->assertOk()->assertJsonPath('data.data.0.body', 'Synthetic interview discussion only.');
        $app = $this->postJson('/api/workforce/v1/jobs/'.$job.'/apply', ['cover_letter' => 'Synthetic application cover letter.', 'share_application' => true], $candidate['headers'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/workforce/v1/jobs/'.$job.'/apply', ['cover_letter' => 'Synthetic duplicate application.', 'share_application' => true], $candidate['headers'])
            ->assertCreated()->assertJsonPath('data.id', $app);
        $this->assertSame(1, DB::table('career_applications')->count());
        $this->assertStringNotContainsString('cover letter', DB::table('career_applications')->first()->payload);
        $this->postJson('/api/workforce/v1/applications/'.$app.'/status', ['status' => 'shortlisted', 'note' => 'Synthetic shortlist review'], $employer['headers'])->assertOk();
        $interview = $this->postJson('/api/workforce/v1/applications/'.$app.'/interviews', [
            'scheduled_at' => now()->addDays(3)->format('Y-m-d\TH:i:sP'), 'timezone' => 'Africa/Lagos', 'instructions' => 'Join the fictional test interview.'], $employer['headers'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/workforce/v1/interviews/'.$interview.'/response', ['response' => 'accepted'], $candidate['headers'])->assertOk();
        $this->postJson('/api/workforce/v1/applications/'.$app.'/status', ['status' => 'offered', 'note' => 'Synthetic employment offer'], $employer['headers'])->assertOk();
        $this->postJson('/api/workforce/v1/applications/'.$app.'/status', ['status' => 'hired', 'note' => 'Must not hire without consent'], $employer['headers'])->assertStatus(409);
        $this->postJson('/api/workforce/v1/applications/'.$app.'/offer-response', ['accepted' => true], $candidate['headers'])->assertOk();
        $this->postJson('/api/workforce/v1/applications/'.$app.'/status', ['status' => 'hired', 'note' => 'Candidate accepted fictional offer'], $employer['headers'])->assertOk();
        $this->getJson('/api/workforce/v1/applications', $candidate['headers'])->assertOk()->assertJsonPath('data.data.0.status', 'hired');
        $this->getJson('/api/workforce/v1/notifications', $candidate['headers'])->assertOk();
    }

    public function test_documents_are_encrypted_and_shared_cv_access_ends_on_withdrawal(): void
    {
        $candidate = $this->candidate();
        $employer = $this->employer();
        $job = $this->job($employer);
        $doc = $this->post('/api/workforce/v1/documents', ['kind' => 'cv', 'file' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\nSynthetic CV\n%%EOF")], $candidate['headers'])
            ->assertCreated()->json('data.id');
        $file = DB::table('career_documents')->find($doc);
        $this->assertStringNotContainsString('%PDF', file_get_contents(storage_path('app/private/career/'.$file->storage_name)));
        $this->get('/api/workforce/v1/documents/'.$doc, $employer['headers'])->assertNotFound();
        $app = $this->postJson('/api/workforce/v1/jobs/'.$job.'/apply', ['cover_letter' => 'Synthetic CV sharing consent.', 'share_application' => true, 'cv_document_id' => $doc], $candidate['headers'])->assertCreated()->json('data.id');
        $this->get('/api/workforce/v1/documents/'.$doc, $employer['headers'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        DB::table('career_documents')->where('id', $doc)->update(['review_status' => 'rejected']);
        $this->get('/api/workforce/v1/documents/'.$doc, $employer['headers'])->assertNotFound();
        $this->get('/api/workforce/v1/documents/'.$doc, $candidate['headers'])->assertOk();
        DB::table('career_documents')->where('id', $doc)->update(['review_status' => 'pending']);
        $this->postJson('/api/workforce/v1/applications/'.$app.'/status', ['status' => 'withdrawn', 'note' => 'Candidate withdraws this application'], $candidate['headers'])->assertOk();
        $this->get('/api/workforce/v1/documents/'.$doc, $employer['headers'])->assertNotFound();
        $this->getJson('/api/workforce/v1/applications', $employer['headers'])->assertOk()->assertJsonMissing(['name' => 'Synthetic candidate']);
    }

    private function course(int $capacity = 1): int
    {
        $this->admin()->post('/career/manage/courses', ['title' => 'Synthetic CNU refresher', 'category' => 'Refresher',
            'description' => 'Fictional course metadata for workflow testing only.', 'eligibility' => 'Synthetic eligible learners',
            'delivery' => 'virtual', 'duration' => 'Two sessions', 'fee_information' => 'Test only; no payment charged',
            'website' => 'https://www.cnumedical.com/', 'capacity' => $capacity, 'published' => 1, 'approved_information' => true])->assertRedirect();

        return DB::table('training_courses')->orderByDesc('id')->value('id');
    }

    public function test_training_enrollment_capacity_completion_certificate_and_credential_consent(): void
    {
        $candidate = $this->candidate();
        $second = $this->candidate('two');
        $employer = $this->employer();
        $course = $this->course();
        $this->getJson('/api/training/v1/courses')->assertOk()->assertJsonPath('data.data.0.id', $course);
        $enroll = $this->postJson('/api/training/v1/courses/'.$course.'/enroll', ['eligibility_attestation' => true, 'enrollment_terms' => true], $candidate['headers'])->assertCreated()->json('data.id');
        $other = $this->postJson('/api/training/v1/courses/'.$course.'/enroll', ['eligibility_attestation' => true, 'enrollment_terms' => true], $second['headers'])->assertCreated()->json('data.id');
        $this->admin()->post('/career/manage/enrollments/'.$enroll, ['status' => 'confirmed', 'note' => 'Eligibility and external enrollment checked'])->assertRedirect();
        $this->admin()->post('/career/manage/enrollments/'.$other, ['status' => 'confirmed', 'note' => 'Capacity must be enforced'])->assertStatus(409);
        $this->admin()->post('/career/manage/enrollments/'.$enroll, ['status' => 'in_progress', 'progress' => 80, 'note' => 'Attendance checked with CNU'])->assertRedirect();
        $this->admin()->post('/career/manage/enrollments/'.$enroll.'/certificate', ['file' => UploadedFile::fake()->createWithContent('certificate.pdf', "%PDF-1.4\nSynthetic certificate\n%%EOF")])->assertRedirect();
        $certificate = DB::table('training_enrollments')->where('id', $enroll)->value('certificate_document_id');
        $this->get('/api/workforce/v1/documents/'.$certificate, $candidate['headers'])->assertNotFound();
        $this->admin()->postJson('/career/manage/enrollments/'.$enroll, ['status' => 'completed', 'note' => 'Missing evidence must fail'])->assertStatus(422);
        $this->admin()->post('/career/manage/enrollments/'.$enroll, ['status' => 'completed', 'note' => 'CNU completion independently confirmed', 'completion_evidence_sha256' => str_repeat('d', 64)])->assertRedirect();
        $this->get('/api/workforce/v1/documents/'.$certificate, $candidate['headers'])->assertOk();
        $this->getJson('/api/training/v1/enrollments', $candidate['headers'])->assertOk()->assertJsonPath('data.data.0.completion_verified', true)->assertJsonPath('data.data.0.progress', 100);
        $cid = DB::table('workforce_candidates')->where('account_id', $candidate['id'])->value('id');
        $this->getJson('/api/workforce/v1/candidates/'.$cid.'/training', $employer['headers'])->assertNotFound();
        $this->portfolio($candidate, true);
        $this->getJson('/api/workforce/v1/candidates/'.$cid.'/training', $employer['headers'])->assertOk()->assertJsonPath('data.0.title', 'Synthetic CNU refresher');
    }

    public function test_organization_training_quotation_acceptance_schedule_and_completion(): void
    {
        $employer = $this->employer();
        $id = $this->postJson('/api/training/v1/requests', ['topic' => 'Synthetic infection prevention refresher',
            'objectives' => 'Fictional training objectives for staff development.', 'participants' => 20, 'location' => 'Synthetic clinic',
            'preferred_date' => today()->addDays(20)->toDateString(), 'delivery' => 'onsite', 'contact_name' => 'Synthetic coordinator',
            'contact_phone' => '+234 000 000 0000', 'requirements' => 'No real training requested.'], $employer['headers'])->assertCreated()->json('data.id');
        $this->admin()->post('/career/manage/requests/'.$id, ['status' => 'quoted', 'note' => 'Synthetic proposal only', 'amount_minor' => 100000,
            'currency' => 'NGN', 'quote_expires' => today()->addDays(10)->toDateString()])->assertRedirect();
        $this->postJson('/api/training/v1/requests/'.$id.'/response', ['accepted' => true], $employer['headers'])->assertOk();
        $this->admin()->post('/career/manage/requests/'.$id, ['status' => 'scheduled', 'note' => 'Fictional session scheduled',
            'starts_at' => now()->addDays(20)->format('Y-m-d\TH:i:sP'), 'facilitator' => 'Synthetic trainer'])->assertRedirect();
        $this->admin()->post('/career/manage/requests/'.$id, ['status' => 'in_progress', 'note' => 'Synthetic delivery started'])->assertRedirect();
        $this->admin()->post('/career/manage/requests/'.$id, ['status' => 'completed', 'note' => 'Synthetic delivery evidence checked',
            'completion_evidence_sha256' => str_repeat('e', 64)])->assertRedirect();
        $this->getJson('/api/training/v1/requests', $employer['headers'])->assertOk()->assertJsonPath('data.data.0.status', 'completed');
        $this->assertStringNotContainsString('Synthetic coordinator', DB::table('training_organization_requests')->first()->details);
    }

    public function test_feature_flags_scopes_revocation_and_unsafe_course_links_fail_closed(): void
    {
        $candidate = $this->candidate();
        $employer = $this->employer();
        $foreign = $this->employer('foreign');
        $job = $this->job($employer);
        $this->postJson('/api/workforce/v1/jobs/'.$job.'/close', [], $foreign['headers'])->assertNotFound();
        DB::table('workforce_employers')->where('account_id', $employer['id'])->update(['verification_status' => 'revoked']);
        $this->getJson('/api/workforce/v1/jobs', $employer['headers'])->assertForbidden();
        $this->getJson('/api/workforce/v1/jobs', $candidate['headers'])->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs(User::findOrFail(901), 'web')->post('/career/manage/courses', [])->assertForbidden();
        $this->admin()->postJson('/career/manage/courses', ['title' => 'Invalid', 'category' => 'Test', 'description' => 'Fictional long course description.',
            'eligibility' => 'Test only', 'delivery' => 'virtual', 'duration' => 'One day', 'fee_information' => 'Test only', 'published' => 1, 'approved_information' => true,
            'website' => 'https://evil.example.test/course'])->assertStatus(422);
        config()->set('career.enabled', false);
        $this->getJson('/api/workforce/v1/jobs', $candidate['headers'])->assertStatus(503);
        $this->getJson('/api/training/v1/courses')->assertStatus(503);
    }

    public function test_privacy_notice_is_enforced_on_server_and_version_changes_require_reconsent(): void
    {
        config()->set('workforce.notice_approved', false);
        $this->postJson('/api/workforce/v1/register', ['name' => 'Blocked applicant'])->assertStatus(503);
        $this->assertSame(0, DB::table('workforce_accounts')->count());
        config()->set('workforce.notice_approved', true);
        $candidate = $this->candidate();
        $this->assertSame('synthetic-v1', DB::table('workforce_accounts')->where('id', $candidate['id'])->value('recruitment_notice_version'));
        config()->set('workforce.notice_version', 'synthetic-v2');
        $this->getJson('/api/workforce/v1/jobs', $candidate['headers'])->assertStatus(409)->assertJsonPath('code', 'CONSENT_REQUIRED');
        $this->postJson('/api/workforce/v1/consent', ['accepted' => true, 'notice_version' => 'synthetic-v1'], $candidate['headers'])->assertStatus(422);
        $this->postJson('/api/workforce/v1/consent', ['accepted' => true, 'notice_version' => 'synthetic-v2'], $candidate['headers'])->assertOk();
        $this->getJson('/api/workforce/v1/jobs', $candidate['headers'])->assertOk();
        config()->set('workforce.notice_url', 'https://care360apps.com.evil.example/privacy');
        $this->postJson('/api/workforce/v1/register', ['name' => 'Blocked applicant'])->assertStatus(503);
    }

    public function test_foreign_applications_private_uploads_and_candidate_self_publishing_are_denied(): void
    {
        $candidate = $this->candidate();
        $other = $this->candidate('other');
        $employer = $this->employer();
        $foreign = $this->employer('foreign');
        $job = $this->job($employer);
        $this->postJson('/api/workforce/v1/jobs', [], $candidate['headers'])->assertForbidden();
        $app = $this->postJson('/api/workforce/v1/jobs/'.$job.'/apply', ['cover_letter' => 'Synthetic private application.', 'share_application' => true], $candidate['headers'])->assertCreated()->json('data.id');
        $this->getJson('/api/workforce/v1/applications', $foreign['headers'])->assertOk()->assertJsonCount(0, 'data.data');
        $this->postJson('/api/workforce/v1/applications/'.$app.'/status', ['status' => 'shortlisted', 'note' => 'Foreign access must fail'], $foreign['headers'])->assertNotFound();
        $this->postJson('/api/workforce/v1/applications/'.$app.'/status', ['status' => 'withdrawn', 'note' => 'Another candidate must fail'], $other['headers'])->assertNotFound();
        $this->post('/api/workforce/v1/documents', ['kind' => 'cv', 'file' => UploadedFile::fake()->createWithContent('not-a-pdf.pdf', 'Plain text is not a PDF.')], $candidate['headers'])->assertStatus(422);
        $this->assertSame(0, DB::table('career_documents')->count());
    }

    public function test_revoked_candidate_cannot_be_progressed_to_a_clinical_job_offer(): void
    {
        $candidate = $this->candidate();
        $employer = $this->employer();
        $job = $this->job($employer);
        $app = $this->postJson('/api/workforce/v1/jobs/'.$job.'/apply', ['cover_letter' => 'Synthetic candidate application.', 'share_application' => true], $candidate['headers'])->assertCreated()->json('data.id');
        $this->postJson('/api/workforce/v1/applications/'.$app.'/status', ['status' => 'shortlisted', 'note' => 'Synthetic review done'], $employer['headers'])->assertOk();
        DB::table('workforce_candidates')->where('account_id', $candidate['id'])->update(['verification_status' => 'revoked']);
        $this->postJson('/api/workforce/v1/applications/'.$app.'/interviews', ['scheduled_at' => now()->addDays(2)->format('Y-m-d\TH:i:sP'),
            'timezone' => 'Africa/Lagos', 'instructions' => 'Synthetic interview invitation.'], $employer['headers'])->assertForbidden();
    }

    public function test_portfolio_rejects_self_verified_nested_fields_and_other_accounts_cannot_read_it(): void
    {
        $candidate = $this->candidate();
        $employer = $this->employer();
        $this->portfolio($candidate);
        $this->getJson('/api/workforce/v1/portfolio', $employer['headers'])->assertForbidden();
        $this->postJson('/api/workforce/v1/portfolio', ['education' => [['institution' => 'Synthetic College', 'qualification' => 'Care', 'year' => 2025, 'verified' => true]],
            'experience' => [], 'skills' => [], 'preferred_locations' => [], 'employment_preferences' => 'Test', 'share_training' => false], $candidate['headers'])->assertStatus(422);
        $this->assertStringNotContainsString('Synthetic College', DB::table('career_portfolios')->first()->payload);
    }

    public function test_recruiter_access_needs_owner_invitation_admin_review_staff_acceptance_and_is_revocable(): void
    {
        $owner = $this->employer();
        $staff = $this->account('employer', 'staff@care360.example.test');
        $id = $this->postJson('/api/workforce/v1/recruiters', ['email' => 'staff@care360.example.test', 'staff_authority_attestation' => true], $owner['headers'])->assertCreated()->json('data.id');
        $this->getJson('/api/workforce/v1/jobs', $staff['headers'])->assertForbidden();
        $this->postJson('/api/workforce/v1/recruiters/'.$id.'/response', ['status' => 'accepted'], $staff['headers'])->assertStatus(409);
        $this->admin()->post('/career/manage/recruiters/'.$id, ['status' => 'approved', 'email_checked' => 1, 'evidence_sha256' => str_repeat('f', 64)])->assertRedirect();
        $this->getJson('/api/workforce/v1/jobs', $staff['headers'])->assertForbidden();
        $this->postJson('/api/workforce/v1/recruiters/'.$id.'/response', ['status' => 'accepted'], $staff['headers'])->assertOk();
        $job = $this->job($owner);
        $this->getJson('/api/workforce/v1/jobs', $staff['headers'])->assertOk()->assertJsonPath('data.data.0.id', $job);
        $foreign = $this->employer('foreign');
        $foreignJob = $this->job($foreign);
        $this->postJson('/api/workforce/v1/jobs/'.$foreignJob.'/close', [], $staff['headers'])->assertNotFound();
        $staffJob = $this->postJson('/api/workforce/v1/jobs', ['title' => 'Delegated test vacancy', 'profession' => 'care_assistant', 'state' => 'Lagos', 'employment_type' => 'contract',
            'description' => 'Fictional vacancy created by an authorized recruiter.', 'requirements' => 'Synthetic qualifying training.', 'closes_on' => today()->addDays(20)->toDateString()], $staff['headers'])->assertCreated()->json('data.id');
        $this->assertSame($owner['id'], DB::table('career_jobs')->where('id', $staffJob)->value('employer_account_id'));
        $this->postJson('/api/workforce/v1/recruiters/'.$id.'/response', ['status' => 'revoked'], $owner['headers'])->assertOk();
        $this->getJson('/api/workforce/v1/jobs', $staff['headers'])->assertForbidden();
    }

    public function test_administration_pages_render_and_escape_untrusted_job_text(): void
    {
        $employer = $this->employer();
        $job = $this->job($employer);
        $this->course();
        DB::table('career_jobs')->where('id', $job)->update(['description' => '<script>Synthetic unsafe markup</script>']);
        $root = sys_get_temp_dir().'/care360-career-view-'.bin2hex(random_bytes(8));
        mkdir($root.'/views/layout', 0700, true);
        mkdir($root.'/compiled', 0700, true);
        file_put_contents($root.'/views/layout/mainlayout_admin.blade.php', '@yield("content")');
        config()->set('view.compiled', $root.'/compiled');
        $this->app['view']->getFinder()->prependLocation($root.'/views');
        try {
            foreach (['jobs', 'documents', 'courses', 'enrollments', 'requests', 'recruiters', 'reports', 'privacy'] as $area) {
                $response = $this->admin()->get('/career/manage?area='.$area)->assertOk();
                if ($area === 'jobs') {
                    $response->assertSee('&lt;script&gt;Synthetic unsafe markup&lt;/script&gt;', false)->assertDontSee('<script>Synthetic unsafe markup</script>', false);
                }
            }
        } finally {
            foreach (glob($root.'/compiled/*.php') as $file) {
                unlink($file);
            }
            unlink($root.'/views/layout/mainlayout_admin.blade.php');
            rmdir($root.'/views/layout');
            rmdir($root.'/views');
            rmdir($root.'/compiled');
            rmdir($root);
        }
    }

    public function test_career_export_and_reviewed_erasure_remove_personal_fields_files_and_sessions_only(): void
    {
        $candidate = $this->candidate();
        $this->portfolio($candidate);
        $doc = $this->post('/api/workforce/v1/documents', ['kind' => 'cv', 'file' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\nSynthetic personal CV\n%%EOF")], $candidate['headers'])->assertCreated()->json('data.id');
        $name = DB::table('career_documents')->where('id', $doc)->value('storage_name');
        config()->set('workforce.notice_version', 'synthetic-v2');
        config()->set('career.enabled', false);
        config()->set('workforce.enabled', false);
        $this->getJson('/api/workforce/v1/privacy/export', $candidate['headers'])->assertOk()->assertJsonPath('data.account.email', 'one@care360.example.test');
        $this->get('/api/workforce/v1/privacy/documents/'.$doc, $candidate['headers'])->assertOk();
        $id = $this->postJson('/api/workforce/v1/privacy', ['confirmation' => true], $candidate['headers'])->assertCreated()->json('data.id');
        $this->assertSame(0, (int) DB::table('workforce_candidates')->where('account_id', $candidate['id'])->value('consent_to_list'));
        $review = ['policy_reference' => 'synthetic-reviewed-policy', 'review_evidence_sha256' => str_repeat('a', 64), 'review_completed' => 1];
        $this->admin()->post('/career/manage/privacy/'.$id, $review)->assertStatus(503);
        config()->set('career.deletion_policy_approved', true);
        $this->admin()->post('/career/manage/privacy/'.$id, $review)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', DB::table('career_privacy_requests')->where('id', $id)->value('status'));
        $this->assertSame('Deleted career account', DB::table('workforce_accounts')->where('id', $candidate['id'])->value('name'));
        $this->assertSame(0, DB::table('workforce_sessions')->where('account_id', $candidate['id'])->count());
        $this->assertSame(0, DB::table('career_documents')->where('account_id', $candidate['id'])->count());
        $this->assertFileDoesNotExist(storage_path('app/private/career/'.$name));
        $this->assertSame(2, DB::table('users')->count());
        $this->getJson('/api/workforce/v1/privacy/export', $candidate['headers'])->assertUnauthorized();
    }

    public function test_erasure_file_failure_stays_processing_and_cleanup_can_be_retried(): void
    {
        $candidate = $this->candidate();
        $doc = $this->post('/api/workforce/v1/documents', ['kind' => 'cv', 'file' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\nSynthetic CV\n%%EOF")], $candidate['headers'])->assertCreated()->json('data.id');
        $name = DB::table('career_documents')->where('id', $doc)->value('storage_name');
        $id = $this->postJson('/api/workforce/v1/privacy', ['confirmation' => true], $candidate['headers'])->assertCreated()->json('data.id');
        config()->set('career.deletion_policy_approved', true);
        DB::table('career_documents')->where('id', $doc)->update(['storage_name' => 'invalid-reference']);
        $review = ['policy_reference' => 'synthetic-policy', 'review_evidence_sha256' => str_repeat('b', 64), 'review_completed' => 1];
        try {
            $this->admin()->post('/career/manage/privacy/'.$id, $review)->assertRedirect()->assertSessionHasErrors('privacy');
            $this->assertSame('processing', DB::table('career_privacy_requests')->where('id', $id)->value('status'));
            $queue = \App\Services\CareerWorkflow::unpack(DB::table('career_privacy_requests')->where('id', $id)->value('details'));
            $queue['document_names'] = [$name];
            DB::table('career_privacy_requests')->where('id', $id)->update(['details' => \App\Services\CareerWorkflow::pack($queue)]);
            DB::table('career_documents')->where('id', $doc)->update(['storage_name' => $name]);
            $this->admin()->post('/career/manage/privacy/'.$id, $review)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('completed', DB::table('career_privacy_requests')->where('id', $id)->value('status'));
        } finally {
            (new CareerDocumentStorage)->remove($name);
        }
    }

    public function test_recovery_is_single_use_expires_and_revokes_career_sessions(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $candidate = $this->candidate();
        $this->postJson('/api/workforce/v1/password-reset/request', ['email' => 'one@care360.example.test'])->assertStatus(202)->assertJsonMissingPath('data.code');
        $code = null;
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CareerPasswordReset::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $this->assertNotEmpty($code);
        $this->assertNotSame($code, DB::table('career_password_resets')->first()->token_hash);
        $payload = ['email' => 'one@care360.example.test', 'code' => $code, 'password' => 'New-Synthetic-Password-2026!', 'password_confirmation' => 'New-Synthetic-Password-2026!'];
        $this->postJson('/api/workforce/v1/password-reset/complete', $payload)->assertOk();
        $this->getJson('/api/workforce/v1/me', $candidate['headers'])->assertUnauthorized();
        $this->postJson('/api/workforce/v1/password-reset/complete', $payload)->assertStatus(422);
        $this->postJson('/api/workforce/v1/password-reset/request', ['email' => 'absent@care360.example.test'])->assertStatus(202);
        \Illuminate\Support\Facades\Mail::assertSentCount(1);
        $this->postJson('/api/workforce/v1/password-reset/request', ['email' => 'one@care360.example.test'])->assertStatus(202);
        DB::table('career_password_resets')->whereNull('redeemed_at')->update(['expires_at' => now()->subMinute()]);
        $latest = \Illuminate\Support\Facades\Mail::sent(\App\Mail\CareerPasswordReset::class)->last()->code;
        $payload['code'] = $latest;
        $this->postJson('/api/workforce/v1/password-reset/complete', $payload)->assertStatus(422);
    }

    public function test_safe_errors_do_not_report_private_exception_text_or_bindings(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $request = \Illuminate\Http\Request::create('/api/workforce/v1/portfolio', 'POST');
        $response = (new \App\Http\Middleware\CareerSafeErrors)->handle($request, function () {
            throw new \RuntimeException('synthetic-private-password-and-profile');
        });
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('synthetic-private-password', $response->getContent());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once()->with('Career workflow request failed.', \Mockery::on(fn ($context) => ! str_contains(json_encode($context), 'synthetic-private-password')));
    }

    public function test_career_migrations_can_resume_without_discarding_existing_records(): void
    {
        $candidate = $this->candidate();
        $this->portfolio($candidate);
        (require database_path('migrations/2026_11_14_000000_add_workforce_notice_consent.php'))->up();
        (require database_path('migrations/2026_11_14_000001_create_career_and_training_workflows.php'))->up();
        $this->assertSame(1,DB::table('career_portfolios')->count());
        $this->getJson('/api/workforce/v1/portfolio',$candidate['headers'])->assertOk()->assertJsonPath('data.skills.0','Communication');
    }
}
