<?php

namespace App\Http\Controllers;

use App\Services\CareerWorkflow as W;
use App\Support\WorkforceRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CareerRecruitmentController extends Controller
{
    public function portfolio(Request $r)
    {
        $a = W::actor($r);
        abort_unless($a->account_type === 'candidate', 403);
        $p = DB::table('career_portfolios')->where('account_id', $a->id)->first();

        return W::response($p ? W::unpack($p->payload) + ['share_training' => (bool) $p->share_training] : []);
    }

    public function savePortfolio(Request $r)
    {
        $a = W::actor($r);
        abort_unless($a->account_type === 'candidate', 403);
        $d = $r->validate([
            'education' => 'present|array|max:20', 'education.*' => 'array:institution,qualification,year', 'education.*.institution' => 'required|string|max:150',
            'education.*.qualification' => 'required|string|max:150', 'education.*.year' => 'required|integer|min:1900|max:2100',
            'experience' => 'present|array|max:20', 'experience.*' => 'array:organization,role,years', 'experience.*.organization' => 'required|string|max:150',
            'experience.*.role' => 'required|string|max:150', 'experience.*.years' => 'required|numeric|min:0|max:70',
            'skills' => 'present|array|max:40', 'skills.*' => 'required|string|max:80',
            'preferred_locations' => 'present|array|max:20', 'preferred_locations.*' => 'required|string|max:100',
            'employment_preferences' => 'required|string|max:1000', 'share_training' => 'required|boolean',
        ]);
        $share = $d['share_training'];
        unset($d['share_training']);
        DB::transaction(function () use ($a, $d, $share) {
            DB::table('career_portfolios')->updateOrInsert(['account_id' => $a->id],
                ['payload' => W::pack($d), 'share_training' => $share, 'experience_years' => (int) min(70, array_sum(array_column($d['experience'], 'years'))), 'updated_at' => now()]);
            W::event('portfolio', $a->id, 'workforce.'.$a->id, 'updated');
        });

        return W::response(['saved' => true]);
    }

    public function jobs(Request $r)
    {
        $a = W::actor($r);
        $d = $r->validate(['q' => 'nullable|string|max:100', 'state' => 'nullable|string|max:80',
            'profession' => ['nullable', Rule::in(array_keys(WorkforceRoles::ROLES))]]);
        $q = DB::table('career_jobs as j')->join('workforce_employers as e', 'e.account_id', '=', 'j.employer_account_id')
            ->join('workforce_accounts as a', 'a.id', '=', 'e.account_id');
        if ($a->account_type === 'employer') {
            $org = W::employer($a);
            $q->where('j.employer_account_id', $org->id);
        } else {
            $q->where('j.status', 'published')->whereDate('j.closes_on', '>=', today())
                ->where('e.verification_status', 'verified')->where('a.is_active', true)->whereNotNull('a.email_verified_at');
        }
        if (! empty($d['q'])) {
            $q->where('j.title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $d['q']).'%');
        }
        foreach (['state', 'profession'] as $f) {
            if (! empty($d[$f])) {
                $q->where('j.'.$f, $d[$f]);
            }
        }

        return W::response($q->orderByDesc('j.id')->paginate(20, ['j.id', 'j.title', 'j.profession', 'j.state',
            'j.employment_type', 'j.description', 'j.requirements', 'j.closes_on', 'j.status', 'e.organization_name']));
    }

    public function saveJob(Request $r)
    {
        $a = W::actor($r);
        $org = W::employer($a);
        $d = $r->validate(['title' => 'required|string|min:3|max:150',
            'profession' => ['required', Rule::in(array_keys(WorkforceRoles::ROLES))],
            'state' => 'required|string|max:80', 'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'internship'])],
            'description' => 'required|string|min:20|max:6000', 'requirements' => 'required|string|min:10|max:4000',
            'closes_on' => 'required|date_format:Y-m-d|after_or_equal:today']);
        $id = DB::transaction(function () use ($a, $d, $org) {
            $id = DB::table('career_jobs')->insertGetId($d + ['employer_account_id' => $org->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            W::event('job', $id, 'workforce.'.$a->id, 'submitted_for_moderation');

            return $id;
        });

        return W::response(['id' => $id, 'status' => 'pending'], 201);
    }

    public function closeJob(Request $r, int $id)
    {
        $a = W::actor($r);
        $org = W::employer($a);
        DB::transaction(function () use ($a, $org, $id) {
            $j = DB::table('career_jobs')->where('id', $id)->where('employer_account_id', $org->id)->lockForUpdate()->first();
            abort_unless($j, 404);
            DB::table('career_jobs')->where('id', $id)->update(['status' => 'closed', 'updated_at' => now()]);
            W::event('job', $id, 'workforce.'.$a->id, 'closed');
        });

        return W::response(['closed' => true]);
    }

    public function apply(Request $r, int $id)
    {
        $a = W::actor($r);
        $p = W::eligible($a);
        $d = $r->validate(['cover_letter' => 'required|string|min:10|max:4000', 'share_application' => 'accepted',
            'cv_document_id' => 'nullable|integer|min:1']);
        $result = DB::transaction(function () use ($a, $p, $d, $id) {
            $j = DB::table('career_jobs')->where('id', $id)->lockForUpdate()->first();
            abort_unless($j && $j->status === 'published' && $j->closes_on >= today()->toDateString(), 404);
            $owner = \App\Models\WorkforceAccount::findOrFail($j->employer_account_id);
            W::owner($owner);
            abort_unless($j->profession === $p->desired_role, 422);
            if (! empty($d['cv_document_id'])) {
                abort_unless(DB::table('career_documents')->where('id', $d['cv_document_id'])
                    ->where('account_id', $a->id)->where('kind', 'cv')->where('review_status', '!=', 'rejected')->exists(), 404);
            }
            $prior = DB::table('career_applications')->where('job_id', $id)->where('candidate_account_id', $a->id)->first();
            if ($prior) {
                return ['id' => $prior->id, 'status' => $prior->status];
            }
            $snapshot = ['name' => $a->name, 'qualification' => $p->qualification, 'institution' => $p->institution,
                'profession' => $p->desired_role, 'state' => $p->state, 'cover_letter' => $d['cover_letter'],
                'shared_at' => now()->toIso8601String()];
            $app = DB::table('career_applications')->insertGetId(['job_id' => $id, 'candidate_account_id' => $a->id,
                'payload' => W::pack($snapshot), 'cv_document_id' => $d['cv_document_id'] ?? null, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);
            W::event('application', $app, 'workforce.'.$a->id, 'submitted');
            W::notify($j->employer_account_id, 'application_received', $app);

            return ['id' => $app, 'status' => 'submitted'];
        });

        return W::response($result, 201);
    }

    public function applications(Request $r)
    {
        $a = W::actor($r);
        $q = DB::table('career_applications as x')->join('career_jobs as j', 'j.id', '=', 'x.job_id');
        if ($a->account_type === 'employer') {
            $org = W::employer($a);
            $q->where('j.employer_account_id', $org->id);
        } else {
            $q->where('x.candidate_account_id', $a->id);
        }
        $rows = $q->orderByDesc('x.id')->paginate(20, ['x.*', 'j.title', 'j.employer_account_id']);
        $rows->setCollection($rows->getCollection()->map(function ($x) {
            $data = ['id' => $x->id, 'job_id' => $x->job_id, 'title' => $x->title, 'status' => $x->status, 'created_at' => $x->created_at];
            if ($x->status !== 'withdrawn') {
                $data['profile'] = W::unpack($x->payload);
            }
            $data['offer_response'] = DB::table('career_workflow_events')->where('subject_type', 'application')->where('subject_id', $x->id)->whereIn('action', ['offer_accepted', 'offer_declined'])->orderByDesc('id')->value('action');
            $data['cv_document_id'] = $x->status === 'withdrawn' ? null : $x->cv_document_id;
            $data['interviews'] = DB::table('career_interviews')->where('application_id', $x->id)->orderByDesc('id')->get()
                ->map(fn ($i) => ['id' => $i->id, 'scheduled_at' => \Carbon\Carbon::parse($i->scheduled_at, 'UTC')->toIso8601String(), 'timezone' => $i->timezone,
                    'candidate_response' => $i->candidate_response, 'details' => $x->status === 'withdrawn' ? [] : W::unpack($i->details)]);

            return $data;
        }));

        return W::response($rows);
    }

    public function transition(Request $r, int $id)
    {
        $a = W::actor($r);
        $d = $r->validate(['status' => ['required', Rule::in(['withdrawn', 'shortlisted', 'rejected', 'interview', 'offered', 'hired'])],
            'note' => 'required|string|min:5|max:1500']);
        DB::transaction(function () use ($a, $d, $id) {
            $x = DB::table('career_applications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($x, 404);
            $j = DB::table('career_jobs')->where('id', $x->job_id)->first();
            if ($a->account_type === 'candidate') {
                abort_unless($x->candidate_account_id === $a->id && $d['status'] === 'withdrawn', 404);
                abort_unless(! in_array($x->status, ['withdrawn', 'rejected', 'hired'], true), 409);
            } else {
                $org = W::employer($a);
                abort_unless((int) $j->employer_account_id === $org->id, 404);
                $allowed = ['submitted' => ['shortlisted', 'rejected'], 'shortlisted' => ['interview', 'rejected'],
                    'interview' => ['offered', 'rejected'], 'offered' => ['hired', 'rejected']];
                abort_unless(in_array($d['status'], $allowed[$x->status] ?? [], true), 409);
                if (in_array($d['status'], ['interview', 'offered', 'hired'], true)) {
                    W::eligible(\App\Models\WorkforceAccount::findOrFail($x->candidate_account_id));
                }
                if ($d['status'] === 'hired') {
                    abort_unless(DB::table('career_workflow_events')->where('subject_type', 'application')->where('subject_id', $id)
                        ->where('action', 'offer_accepted')->exists(), 409);
                }
            }
            DB::table('career_applications')->where('id', $id)->update(['status' => $d['status'], 'updated_at' => now()]);
            W::event('application', $id, 'workforce.'.$a->id, $d['status'], ['note' => $d['note']]);
            W::notify($a->account_type === 'candidate' ? $j->employer_account_id : $x->candidate_account_id, 'application_status', $id);
        });

        return W::response(['updated' => true]);
    }

    public function offerResponse(Request $r, int $id)
    {
        $a = W::actor($r);
        $d = $r->validate(['accepted' => 'required|boolean']);
        DB::transaction(function () use ($a, $d, $id) {
            $x = DB::table('career_applications')->where('id', $id)->where('candidate_account_id', $a->id)->lockForUpdate()->first();
            abort_unless($a->account_type === 'candidate' && $x, 404);
            abort_unless($x->status === 'offered', 409);
            abort_if(DB::table('career_workflow_events')->where('subject_type', 'application')->where('subject_id', $id)
                ->whereIn('action', ['offer_accepted', 'offer_declined'])->exists(), 409);
            W::event('application', $id, 'workforce.'.$a->id, $d['accepted'] ? 'offer_accepted' : 'offer_declined');
            if (! $d['accepted']) {
                DB::table('career_applications')->where('id', $id)->update(['status' => 'withdrawn', 'updated_at' => now()]);
            }
            $j = DB::table('career_jobs')->find($x->job_id);
            W::notify($j->employer_account_id, 'offer_response', $id);
        });

        return W::response(['recorded' => true]);
    }

    public function interview(Request $r, int $id)
    {
        $a = W::actor($r);
        $org = W::employer($a);
        $d = $r->validate([
            'scheduled_at' => 'required|date_format:Y-m-d\TH:i:sP|after:now', 'timezone' => 'required|timezone',
            'instructions' => 'required|string|min:5|max:2000']);
        $interview = DB::transaction(function () use ($a, $org, $d, $id) {
            $x = DB::table('career_applications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($x, 404);
            $j = DB::table('career_jobs')->find($x->job_id);
            abort_unless((int) $j->employer_account_id === $org->id, 404);
            abort_unless(in_array($x->status, ['shortlisted', 'interview'], true), 409);
            W::eligible(\App\Models\WorkforceAccount::findOrFail($x->candidate_account_id));
            $i = DB::table('career_interviews')->insertGetId(['application_id' => $id,
                'scheduled_at' => \Carbon\Carbon::parse($d['scheduled_at'])->utc(), 'timezone' => $d['timezone'],
                'details' => W::pack(['instructions' => $d['instructions']]), 'candidate_response' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('career_applications')->where('id', $id)->update(['status' => 'interview', 'updated_at' => now()]);
            W::event('application', $id, 'workforce.'.$a->id, 'interview_scheduled', ['interview_id' => $i]);
            W::notify($x->candidate_account_id, 'interview_invitation', $id);

            return $i;
        });

        return W::response(['id' => $interview], 201);
    }

    public function interviewResponse(Request $r, int $id)
    {
        $a = W::actor($r);
        $d = $r->validate(['response' => ['required', Rule::in(['accepted', 'declined'])]]);
        DB::transaction(function () use ($a, $d, $id) {
            $i = DB::table('career_interviews')->where('id', $id)->lockForUpdate()->first();
            abort_unless($i, 404);
            $x = DB::table('career_applications')->find($i->application_id);
            abort_unless($x->candidate_account_id === $a->id && $x->status === 'interview', 404);
            abort_unless($i->candidate_response === 'pending', 409);
            DB::table('career_interviews')->where('id', $id)->update(['candidate_response' => $d['response'], 'updated_at' => now()]);
            W::event('application', $x->id, 'workforce.'.$a->id, 'interview_'.$d['response']);
        });

        return W::response(['recorded' => true]);
    }

    public function readNotification(Request $r, int $id)
    {
        $a = W::actor($r);
        abort_unless(DB::table('career_notifications')->where('id', $id)->where('account_id', $a->id)->exists(), 404);
        DB::table('career_notifications')->where('id', $id)->where('account_id', $a->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return W::response(['read' => true]);
    }

    public function notifications(Request $r)
    {
        return W::response(DB::table('career_notifications')->where('account_id', W::actor($r)->id)->orderByDesc('id')->paginate(30));
    }
}
