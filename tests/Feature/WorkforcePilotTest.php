<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkforcePilotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        config()->set('cache.default', 'array');
        config()->set('workforce.enabled', true);
        DB::purge('sqlite');
        $migration = require database_path(
            'migrations/2026_11_07_230000_create_workforce_pilot_tables.php'
        );
        $migration->up();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
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
            'workforce_terms_accepted' => true,
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
            'desired_role' => 'Supervised care assistant',
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
}
