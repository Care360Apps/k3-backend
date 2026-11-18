<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Deletes direct career personal fields and files; never touches clinical users.
 * An approved policy and documented human review are mandatory because other
 * parties may have legally retained recruitment records outside this platform.
 */
final class CareerErasureService
{
    public function fulfill(int $id, string $reviewer, string $policy, string $evidence): void
    {
        abort_unless(config('career.deletion_policy_approved', false), 503);
        $queue = DB::transaction(function () use ($id, $reviewer, $policy, $evidence) {
            $request = DB::table('career_privacy_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($request, 404);
            abort_unless(in_array($request->status, ['requested', 'processing'], true), 409);
            if ($request->status === 'processing') {
                return CareerWorkflow::unpack($request->details);
            }
            $account = (int) $request->account_id;
            DB::table('workforce_accounts')->where('id', $account)->lockForUpdate()->first();
            $documents = DB::table('career_documents')->where('account_id', $account)->get();
            $applications = DB::table('career_applications')->where('candidate_account_id', $account)->pluck('id');
            $enrollments = DB::table('training_enrollments')->where('account_id', $account)->pluck('id');
            $requests = DB::table('training_organization_requests')->where('account_id', $account)->pluck('id');
            $jobs = DB::table('career_jobs')->where('employer_account_id', $account)->pluck('id');
            $invitations = DB::table('workforce_invitations')->where('candidate_account_id', $account)->orWhere('employer_account_id', $account)->pluck('id');
            $redacted = CareerWorkflow::pack(['redacted' => 'Career personal fields erased under reviewed policy.']);
            DB::table('workforce_sessions')->where('account_id', $account)->delete();
            DB::table('career_password_resets')->where('account_id', $account)->delete();
            DB::table('workforce_accounts')->where('id', $account)->update(['name' => 'Deleted career account',
                'email' => 'deleted-'.Str::uuid().'@deleted.invalid', 'password' => Hash::make(Str::random(64)),
                'email_verified_at' => null, 'is_active' => false, 'updated_at' => now()]);
            foreach (['candidate' => 'workforce_candidates', 'employer' => 'workforce_employers'] as $kind => $table) {
                $profileIds = DB::table($table)->where('account_id', $account)->pluck('id');
                DB::table('workforce_verification_events')->where('subject_type', $kind)->whereIn('subject_id', $profileIds)
                    ->update(['evidence_sha256' => str_repeat('0', 64)]);
                DB::table($table)->where('account_id', $account)->delete();
            }
            DB::table('career_portfolios')->where('account_id', $account)->delete();
            DB::table('career_applications')->whereIn('id', $applications)->update(['payload' => $redacted, 'cv_document_id' => null, 'updated_at' => now()]);
            DB::table('career_interviews')->whereIn('application_id', $applications)->update(['details' => $redacted, 'updated_at' => now()]);
            DB::table('career_jobs')->whereIn('id', $jobs)->update(['title' => 'Withdrawn vacancy', 'description' => 'Organization career account closed.',
                'requirements' => 'Unavailable', 'status' => 'closed', 'updated_at' => now()]);
            DB::table('training_enrollments')->whereIn('id', $enrollments)->update(['details' => $redacted, 'certificate_document_id' => null,
                'completion_evidence_sha256' => null, 'updated_at' => now()]);
            DB::table('training_organization_requests')->whereIn('id', $requests)->update(['topic' => 'Closed organization request',
                'details' => $redacted, 'updated_at' => now()]);
            DB::table('workforce_messages')->where('sender_account_id', $account)->update(['body_encrypted' => CareerWorkflow::pack(['body' => 'Message removed following career account erasure.']), 'updated_at' => now()]);
            DB::table('workforce_invitations')->whereIn('id', $invitations)->update(['status' => 'closed', 'updated_at' => now()]);
            DB::table('workforce_invitations')->where('employer_account_id', $account)->update(['role_title' => 'Closed recruitment',
                'job_summary' => 'Organization career account closed.', 'updated_at' => now()]);
            DB::table('career_recruiters')->where('owner_account_id', $account)->orWhere('recruiter_account_id', $account)->update(['status' => 'revoked', 'updated_at' => now()]);
            DB::table('career_recruiters')->where('recruiter_account_id', $account)->update(['evidence_sha256' => null]);
            DB::table('career_notifications')->where('account_id', $account)->delete();
            foreach (['portfolio' => [$account], 'application' => $applications, 'enrollment' => $enrollments, 'training_request' => $requests,
                'job' => $jobs, 'invitation' => $invitations, 'document' => $documents->pluck('id')] as $type => $ids) {
                DB::table('career_workflow_events')->where('subject_type', $type)->whereIn('subject_id', $ids)->update(['details' => $redacted]);
            }
            $details = ['policy_reference' => $policy, 'review_evidence_sha256' => $evidence, 'reviewer' => $reviewer,
                'document_names' => $documents->pluck('storage_name')->all(), 'document_count' => $documents->count()];
            DB::table('career_privacy_requests')->where('id', $id)->update(['status' => 'processing', 'details' => CareerWorkflow::pack($details), 'updated_at' => now()]);

            return $details;
        });
        // Idempotent cleanup after database redaction. An interrupted/failed
        // file removal leaves processing status and an encrypted retry queue.
        $storage = new CareerDocumentStorage;
        foreach ($queue['document_names'] as $name) {
            $storage->erase($name);
        }
        DB::transaction(function () use ($id, $queue, $reviewer) {
            $r = DB::table('career_privacy_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($r && $r->status === 'processing', 409);
            DB::table('career_documents')->where('account_id', $r->account_id)->delete();
            unset($queue['document_names']);
            $queue['scope'] = 'Direct career personal fields and files erased; limited de-identified workflow references retained under reviewed policy. Clinical accounts are separate.';
            DB::table('career_privacy_requests')->where('id', $id)->update(['status' => 'completed', 'details' => CareerWorkflow::pack($queue), 'updated_at' => now()]);
            CareerWorkflow::event('privacy_request',$id,$reviewer,'completed',['policy_reference' => $queue['policy_reference'], 'document_count' => $queue['document_count']]);
        });
    }
}
