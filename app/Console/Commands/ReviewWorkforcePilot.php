<?php

namespace App\Console\Commands;

use App\Models\WorkforceCandidate;
use App\Models\WorkforceEmployer;
use App\Support\WorkforceRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReviewWorkforcePilot extends Command
{
    protected $signature = 'workforce:review
        {kind : candidate|employer}
        {id : Numeric profile record ID}
        {decision : verified|rejected|revoked}
        {--reviewer= : Authorized internal reviewer ID}
        {--evidence-sha256= : SHA256 of separately stored credential review evidence}
        {--licence-expires= : Verified licence expiry date YYYY-MM-DD}
        {--licence-evidence-sha256= : Separate professional registry verification evidence SHA256}
        {--email-checked : Operator has independently verified applicant email ownership}
        {--confirm : Explicitly authorize the decision}';

    protected $description = 'Local/staging Workforce reviewer only; never self-service or a public endpoint';

    public function handle(): int
    {
        if (! app()->environment('local', 'staging', 'testing')
            || ! config('workforce.enabled', false)) {
            $this->error('Reviews are disabled outside an enabled isolated pilot.');
            return self::FAILURE;
        }
        $kind = (string) $this->argument('kind');
        $id = (string) $this->argument('id');
        $decision = (string) $this->argument('decision');
        $reviewer = trim((string) $this->option('reviewer'));
        $digest = strtolower(trim((string) $this->option('evidence-sha256')));
        $licenceDigest = strtolower(trim((string) $this->option('licence-evidence-sha256')));
        $licenceExpiry = trim((string) $this->option('licence-expires'));

        if (! $this->option('confirm')
            || ! in_array($kind, ['candidate', 'employer'], true)
            || ! ctype_digit($id) || (int) $id < 1
            || ! in_array($decision, ['verified', 'rejected', 'revoked'], true)
            || ! preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $reviewer)
            || ! preg_match('/^[a-f0-9]{64}$/', $digest)) {
            $this->error('Invalid review request. Authorized reviewer, evidence SHA256 and --confirm required.');
            return self::FAILURE;
        }

        try {
            DB::transaction(function () use (
                $kind, $id, $decision, $reviewer,
                $digest, $licenceDigest, $licenceExpiry
            ) {
                $record = $kind === 'candidate'
                    ? WorkforceCandidate::query()->whereKey((int) $id)->lockForUpdate()->firstOrFail()
                    : WorkforceEmployer::query()->whereKey((int) $id)->lockForUpdate()->firstOrFail();

                $account = $record->account;
                if (! $account || ! $account->is_active) {
                    throw new \DomainException('Account not active.');
                }

                if ($decision === 'verified') {
                    if (! $this->option('email-checked')) {
                        throw new \DomainException('Independent email check is mandatory.');
                    }
                    if ($kind === 'candidate') {
                        if (! $record->institution || ! $record->qualification
                            || ! $record->desired_role || ! $record->state) {
                            throw new \DomainException('Complete training/role fields before review.');
                        }
                        if (WorkforceRoles::isLicenceRequired($record->desired_role)
                            || $record->career_stage === 'licensed') {
                            if (! preg_match('/^[a-f0-9]{64}$/', $licenceDigest)
                                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $licenceExpiry)
                                || $licenceExpiry < today()->toDateString()) {
                                throw new \DomainException('Current independent licence and evidence are required.');
                            }
                            $record->licence_status = 'verified';
                            $record->licence_expires_at = $licenceExpiry;
                        }
                    } else {
                        if (! $record->organization_name || ! $record->registration_number) {
                            throw new \DomainException('Confirm organization and registration information.');
                        }
                    }
                    $account->email_verified_at = $account->email_verified_at ?? now();
                    $account->save();
                }

                if ($kind === 'candidate' && $decision !== 'verified') {
                    $record->licence_status = 'not_verified';
                    $record->licence_expires_at = null;
                }
                $record->verification_status = $decision;
                $record->save();

                DB::table('workforce_verification_events')->insert([
                    'subject_type' => $kind,
                    'subject_id' => $record->id,
                    'reviewer_reference' => $reviewer,
                    'decision' => $decision,
                    'evidence_sha256' => $digest,
                    'created_at' => now(),
                ]);
                if ($kind === 'candidate' && $decision === 'verified'
                    && $licenceDigest !== '') {
                    DB::table('workforce_verification_events')->insert([
                        'subject_type' => 'professional_licence',
                        'subject_id' => $record->id,
                        'reviewer_reference' => $reviewer,
                        'decision' => 'verified',
                        'evidence_sha256' => $licenceDigest,
                        'created_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable $error) {
            // Do not output actual submitted credentials or private files.
            $this->error('Review rejected. Check role, evidence and profile requirements.');
            return self::FAILURE;
        }

        $this->info('Workforce review recorded: '.$kind.' #'.$id.' => '.$decision);
        return self::SUCCESS;
    }
}
