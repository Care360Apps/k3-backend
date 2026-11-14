<?php

namespace App\Http\Controllers;

use App\Services\CareerDocumentStorage;
use App\Services\CareerWorkflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CareerPrivacyController extends Controller
{
    public function index(Request $r)
    {
        return W::response(DB::table('career_privacy_requests')->where('account_id', W::actor($r)->id)
            ->orderByDesc('id')->paginate(20, ['id', 'status', 'created_at', 'updated_at']));
    }

    public function export(Request $r)
    {
        $a = W::actor($r);
        $a->load('candidate', 'employer');
        $extra = DB::table('career_jobs')->where('employer_account_id', $a->id)->count() + DB::table('workforce_messages')->where('sender_account_id', $a->id)->count();
        $total = $extra + DB::table('career_applications')->where('candidate_account_id', $a->id)->count() + DB::table('training_enrollments')->where('account_id', $a->id)->count() + DB::table('training_organization_requests')->where('account_id', $a->id)->count();
        abort_if($total > 500, 413, 'Large career exports require the support-assisted export process.');
        $portfolio = DB::table('career_portfolios')->where('account_id', $a->id)->first();
        $applications = DB::table('career_applications')->where('candidate_account_id', $a->id)->get()->map(fn ($x) => [
            'id' => $x->id, 'job_id' => $x->job_id, 'status' => $x->status, 'submitted_data' => W::unpack($x->payload), 'created_at' => $x->created_at]);
        $training = DB::table('training_enrollments')->where('account_id', $a->id)->get()->map(fn ($x) => [
            'id' => $x->id, 'course_id' => $x->course_id, 'status' => $x->status, 'progress' => $x->progress, 'details' => W::unpack($x->details)]);
        $requests = DB::table('training_organization_requests')->where('account_id', $a->id)->get()->map(fn ($x) => [
            'id' => $x->id, 'topic' => $x->topic, 'status' => $x->status, 'details' => W::unpack($x->details)]);

        return W::response(['exported_at' => now()->toIso8601String(), 'account' => ['name' => $a->name, 'email' => $a->email, 'role' => $a->account_type],
            'candidate_profile' => $a->candidate, 'organization_profile' => $a->employer,
            'portfolio' => $portfolio ? W::unpack($portfolio->payload) : [],
            'applications' => $applications,
            'published_jobs' => DB::table('career_jobs')->where('employer_account_id', $a->id)->get(['id', 'title', 'profession', 'state', 'employment_type', 'description', 'requirements', 'closes_on', 'status']),
            'sent_recruitment_messages' => DB::table('workforce_messages')->where('sender_account_id', $a->id)->get()->map(fn ($m) => ['invitation_id' => $m->invitation_id, 'body' => W::unpack($m->body_encrypted)['body'], 'created_at' => $m->created_at]),
            'training_enrollments' => $training, 'organization_training' => $requests,
            'documents' => DB::table('career_documents')->where('account_id', $a->id)->get(['id', 'kind', 'mime', 'bytes', 'created_at'])]);
    }

    public function requestClosure(Request $r)
    {
        $a = W::actor($r);
        $r->validate(['confirmation' => 'accepted']);
        $id = DB::transaction(function () use ($a) {
            DB::table('workforce_accounts')->where('id', $a->id)->lockForUpdate()->first();
            $old = DB::table('career_privacy_requests')->where('account_id', $a->id)->whereIn('status', ['requested', 'processing'])->first();
            if ($old) {
                return $old->id;
            }
            $id = DB::table('career_privacy_requests')->insertGetId(['account_id' => $a->id, 'status' => 'requested',
                'details' => W::pack(['requested_at' => now()->toIso8601String()]), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('workforce_candidates')->where('account_id', $a->id)->update(['consent_to_list' => false, 'available_for_work' => false, 'updated_at' => now()]);
            DB::table('career_applications')->where('candidate_account_id', $a->id)->update(['cv_document_id' => null, 'updated_at' => now()]);
            DB::table('career_jobs')->where('employer_account_id', $a->id)->update(['status' => 'closed', 'updated_at' => now()]);
            DB::table('career_recruiters')->where('owner_account_id', $a->id)->orWhere('recruiter_account_id', $a->id)->update(['status' => 'revoked', 'updated_at' => now()]);
            W::event('privacy_request', $id, 'workforce.'.$a->id, 'closure_requested');

            return $id;
        });

        return W::response(['id' => $id, 'status' => 'requested', 'message' => 'Recruitment visibility and new processing are paused. Export your data now. An authorized administrator must complete the deletion review and erasure.'], 201);
    }

    public function document(Request $r, int $id, CareerDocumentStorage $storage)
    {
        $a = W::actor($r);
        $doc = DB::table('career_documents')->where('id', $id)->where('account_id', $a->id)->first();
        abort_unless($doc, 404);
        if ($doc->kind === 'certificate') {
            abort_unless(DB::table('training_enrollments')->where('account_id', $a->id)->where('certificate_document_id',$id)->where('status','completed')->exists(), 404);
        }

        return $storage->read($doc);
    }
}
