<?php
namespace App\Services;
use App\Models\WorkforceCandidate;
use App\Models\WorkforceEmployer;
use App\Support\WorkforceRoles;
use Illuminate\Support\Facades\DB;
class WorkforceReviewService
{
 public function review(string $kind, int $id, string $decision, string $reviewer, string $digest, bool $emailChecked, string $licenceDigest = '', string $licenceExpiry = ''): void
 {
  if (!in_array($kind,['candidate','employer'],true) || !in_array($decision,['verified','rejected','revoked'],true)
      || !preg_match('/^[a-f0-9]{64}$/',$digest) || $reviewer === '') {
   throw new \DomainException('Invalid review.');
  }
  DB::transaction(function () use ($kind,$id,$decision,$reviewer,$digest,$emailChecked,$licenceDigest,$licenceExpiry) {
    $record = $kind === 'candidate'
        ? WorkforceCandidate::query()->whereKey((int) $id)->lockForUpdate()->firstOrFail()
        : WorkforceEmployer::query()->whereKey((int) $id)->lockForUpdate()->firstOrFail();

    $account = $record->account;
    if (! $account || ! $account->is_active) {
        throw new \DomainException('Account not active.');
    }

    if ($decision === 'verified') {
        if (! $emailChecked) {
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
 }
}
