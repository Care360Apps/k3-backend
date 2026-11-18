<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkforcePilotTest extends TestCase
{
    use \Tests\Support\SeedsWebLocalization;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('w',32)));
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        config()->set('cache.default', 'array');
        config()->set('view.compiled', sys_get_temp_dir());
        config()->set('workforce.enabled', true);
        config()->set('workforce.notice_approved',true);config()->set('workforce.notice_url','https://care360apps.com/recruitment-privacy');config()->set('workforce.notice_version','synthetic-v1');
        DB::purge('sqlite');
  $this->seedWebLocalization();
        $migration = require database_path(
            'migrations/2026_11_07_230000_create_workforce_pilot_tables.php'
        );
        $migration->up();
        (require database_path('migrations/2026_11_07_231000_create_workforce_verification_and_invitations.php'))->up();
        (require database_path('migrations/2026_11_14_000000_add_workforce_notice_consent.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_index_names_fit_the_mysql_and_mariadb_identifier_limit(): void
    {
        foreach (['workforce_accounts', 'workforce_sessions', 'workforce_candidates', 'workforce_employers'] as $table) {
            $indexes = DB::connection()->getSchemaBuilder()->getIndexes($table);
            $this->assertNotEmpty($indexes);
            foreach ($indexes as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), 'Index name exceeds MySQL/MariaDB limit: '.$index['name']);
            }
        }
    }

    private function signUp(string $type, string $email): array
    {
        $data = [
            'name' => 'Synthetic Applicant',
            'email' => $email,
            'password' => 'Very-Synthetic-Password-2026!',
            'password_confirmation' => 'Very-Synthetic-Password-2026!',
            'account_type' => $type,
            'adult_attestation' => true,
            'workforce_terms_accepted' => true, 'notice_version'=>'synthetic-v1',
        ];
        $this->postJson('/api/workforce/v1/register', $data)->assertCreated();
        $login = $this->postJson('/api/workforce/v1/login', [
            'email' => $email, 'password' => $data['password'],
        ])->assertOk()->json('data');
        return [
            'token' => $login['token'],
            'role' => $login['role'],
        ];
    }

    public function test_pilot_off_rejects_registration_and_returns_no_candidate_info(): void
    {
        config()->set('workforce.enabled', false);
        $this->getJson('/api/workforce/v1/status')
            ->assertOk()->assertJsonPath('data.applications_open', false);
        $this->postJson('/api/workforce/v1/register', [
            'name' => 'Blocked',
        ])->assertStatus(503)->assertJsonPath('code', 'WORKFORCE_NOT_OPEN');
        $this->assertSame(0, DB::table('workforce_accounts')->count());
    }

    public function test_candidate_profile_is_private_and_cannot_self_verify(): void
    {
        $account = $this->signUp('candidate', 'candidate@example.test');
        $header = ['Authorization' => 'Bearer '.$account['token']];
        $this->postJson('/api/workforce/v1/candidate/profile', [
            'career_stage' => 'student',
            'institution' => 'Synthetic College of Health',
            'qualification' => 'Community Health Training',
            'desired_role' => 'care_assistant',
            'state' => 'Nasarawa',
            'city' => 'Keffi',
            'shift_preference' => 'weekdays',
            'available_for_work' => true,
            'consent_to_list' => true,
            'verification_status' => 'verified', // Tampering must be ignored.
            'licence_status' => 'verified',
        ], $header)->assertOk()
            ->assertJsonPath('data.verification_status', 'pending');

        $profile = DB::table('workforce_candidates')->first();
        $this->assertSame('pending', $profile->verification_status);
        $this->assertSame('not_verified', $profile->licence_status);
        $this->assertSame(1, (int) $profile->consent_to_list);
        $this->getJson('/api/workforce/v1/candidates', $header)->assertForbidden();
        $this->getJson('/api/workforce/v1/me', $header)
            ->assertOk()->assertJsonPath('data.account_type', 'candidate');
    }

    public function test_pending_employer_cannot_browse_or_self_approve(): void
    {
        $account = $this->signUp('employer', 'employer@example.test');
        $header = ['Authorization' => 'Bearer '.$account['token']];
        $this->postJson('/api/workforce/v1/employer/profile', [
            'organization_name' => 'Synthetic Hospital',
            'organization_type' => 'hospital',
            'registration_number' => 'TEST-CAC-001',
            'state' => 'Nasarawa',
            'verification_status' => 'verified', // Tampering must be ignored.
        ], $header)->assertOk()
            ->assertJsonPath('data.verification_status', 'pending');
        $this->assertSame('pending',
            DB::table('workforce_employers')->first()->verification_status);
        $this->getJson('/api/workforce/v1/candidates', $header)
            ->assertStatus(403)->assertJsonPath('code', 'EMPLOYER_NOT_VERIFIED');
        $this->postJson('/api/workforce/v1/candidate/profile', [], $header)
            ->assertForbidden();
    }

    public function test_unlicensed_nurse_cannot_appear_as_verified_professional(): void
    {
        $candidate = $this->signUp('candidate', 'nurse-applicant@example.test');
        $candidateHeaders = ['Authorization' => 'Bearer '.$candidate['token']];
        $profile = [
            'career_stage' => 'graduate',
            'institution' => 'Synthetic Health College',
            'qualification' => 'Nursing Degree',
            'desired_role' => 'nurse',
            'state' => 'Nasarawa',
            'city' => 'Keffi',
            'shift_preference' => 'weekdays',
            'available_for_work' => true,
            'consent_to_list' => true,
        ];
        $this->postJson('/api/workforce/v1/candidate/profile',
            $profile, $candidateHeaders)->assertOk();
        $candidateId = DB::table('workforce_candidates')->first()->id;
        $candidateAccountId = DB::table('workforce_candidates')->first()->account_id;

        // Simulate an out-of-band education review, not a licence review.
        DB::table('workforce_candidates')->where('id', $candidateId)
            ->update(['verification_status' => 'verified']);
        DB::table('workforce_accounts')->where('id', $candidateAccountId)
            ->update(['email_verified_at' => now()]);

        $employer = $this->signUp('employer', 'approved-hospital@example.test');
        $employerHeaders = ['Authorization' => 'Bearer '.$employer['token']];
        $this->postJson('/api/workforce/v1/employer/profile', [
            'organization_name' => 'Approved Synthetic Hospital',
            'organization_type' => 'hospital',
            'registration_number' => 'CAC-TEST-1001',
            'state' => 'Nasarawa',
        ], $employerHeaders)->assertOk();
        $employerId = DB::table('workforce_employers')->first()->account_id;
        DB::table('workforce_employers')->where('account_id', $employerId)
            ->update(['verification_status' => 'verified']);
        DB::table('workforce_accounts')->where('id', $employerId)
            ->update(['email_verified_at' => now()]);

        $this->getJson('/api/workforce/v1/candidates', $employerHeaders)
            ->assertOk()->assertJsonCount(0, 'data');

        DB::table('workforce_candidates')->where('id', $candidateId)
            ->update([
                'licence_status' => 'verified',
                'licence_expires_at' => now()->addMonths(6)->toDateString(),
            ]);
        $this->getJson('/api/workforce/v1/candidates', $employerHeaders)
            ->assertOk()->assertJsonCount(1, 'data');

        // Material changes invalidate institutional and licence review.
        $profile['qualification'] = 'Changed qualification without evidence';
        $this->postJson('/api/workforce/v1/candidate/profile',
            $profile, $candidateHeaders)
            ->assertOk()->assertJsonPath('data.verification_status', 'pending');
        $this->getJson('/api/workforce/v1/candidates', $employerHeaders)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sessions_are_hashed_separate_and_revocable(): void
    {
        $account = $this->signUp('candidate', 'another@example.test');
        $raw = $account['token'];
        $this->assertGreaterThanOrEqual(60, strlen($raw));
        $this->assertSame(hash('sha256', $raw),
            DB::table('workforce_sessions')->first()->token_hash);
        $header = ['Authorization' => 'Bearer '.$raw];
        $this->getJson('/api/workforce/v1/me', $header)->assertOk();
        $this->postJson('/api/workforce/v1/logout', [], $header)->assertOk();
        $this->getJson('/api/workforce/v1/me', $header)->assertUnauthorized();
    }

    public function test_review_requires_evidence_and_email_check_and_records_audit(): void
    {
        $this->signUp('employer', 'review@example.test');
        $account = DB::table('workforce_accounts')->where('email','review@example.test')->first();
        $id = DB::table('workforce_employers')->insertGetId([
            'account_id'=>$account->id, 'organization_name'=>'Synthetic Employer',
            'organization_type'=>'hospital','registration_number'=>'SYNTHETIC-ONLY','state'=>'Lagos',
            'verification_status'=>'pending','created_at'=>now(),'updated_at'=>now(),
        ]);
        $service = new \App\Services\WorkforceReviewService();
        try {
            $service->review('employer',$id,'verified','admin.777',str_repeat('a',64),false);
            $this->fail('Email check bypassed');
        } catch (\DomainException $e) {
            $this->assertSame('pending',DB::table('workforce_employers')->where('id',$id)->value('verification_status'));
        }
        $service->review('employer',$id,'verified','admin.777',str_repeat('a',64),true);
        $this->assertSame('verified',DB::table('workforce_employers')->where('id',$id)->value('verification_status'));
        $this->assertSame(1,DB::table('workforce_verification_events')->where('subject_id',$id)->count());
        $this->assertNotNull(DB::table('workforce_accounts')->where('id',$account->id)->value('email_verified_at'));
        $service->review('employer',$id,'revoked','admin.777',str_repeat('b',64),false);
        $this->assertSame('revoked',DB::table('workforce_employers')->where('id',$id)->value('verification_status'));
    }

    public function test_workforce_review_web_route_rejects_non_reviewer(): void
    {
        \Illuminate\Support\Facades\Schema::create('permissions', function (\Illuminate\Database\Schema\Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); });
        $user = new class extends \App\Models\User {
            public function hasRole($roles, ?string $guard = null): bool { return false; }
        };
        $user->id = 777;
        $this->actingAs($user)->postJson('/workforce/review',[
            'kind'=>'employer','profile_id'=>1,'decision'=>'verified',
            'evidence_sha256'=>str_repeat('a',64),'email_checked'=>true,
        ])->assertForbidden();
    }

    public function test_local_demo_rejects_real_applicants(): void
    {
        config()->set('workforce.local_demo', true);
        $this->postJson('/api/workforce/v1/register', ['email'=>'real.person@example.com'])
            ->assertUnprocessable();
        $this->assertSame(0, DB::table('workforce_accounts')->count());
    }
}
