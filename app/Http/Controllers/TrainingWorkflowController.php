<?php

namespace App\Http\Controllers;

use App\Services\CareerWorkflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TrainingWorkflowController extends Controller
{
    public function courses(Request $r)
    {
        $d = $r->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:80']);
        $q = DB::table('training_courses')->where('published', true);
        if (! empty($d['q'])) {
            $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $d['q']).'%');
        }
        if (! empty($d['category'])) {
            $q->where('category', $d['category']);
        }
        $rows = $q->orderByDesc('id')->paginate(20, ['id', 'title', 'category', 'description', 'eligibility',
            'delivery', 'duration', 'fee_information', 'website', 'starts_at', 'capacity']);
        $rows->setCollection($rows->getCollection()->map(function ($c) {
            if ($c->starts_at) {
                $c->starts_at = \Carbon\Carbon::parse($c->starts_at, 'UTC')->toIso8601String();
            }

            return $c;
        }));

        return W::response($rows);
    }

    public function enroll(Request $r, int $id)
    {
        $a = W::actor($r);
        $r->validate(['eligibility_attestation' => 'accepted', 'enrollment_terms' => 'accepted']);
        $row = DB::transaction(function () use ($a, $id) {
            $c = DB::table('training_courses')->where('id', $id)->where('published', true)->lockForUpdate()->first();
            abort_unless($c, 404);
            abort_unless(! $c->starts_at || \Carbon\Carbon::parse($c->starts_at)->isFuture(), 409);
            $old = DB::table('training_enrollments')->where('course_id', $id)->where('account_id', $a->id)->first();
            if ($old) {
                return ['id' => $old->id, 'status' => $old->status];
            }
            $enrollment = DB::table('training_enrollments')->insertGetId(['course_id' => $id, 'account_id' => $a->id,
                'status' => 'requested', 'progress' => 0, 'details' => W::pack(['requested_at' => now()->toIso8601String(),
                    'terms' => 'manual_cnu_confirmation_v1', 'payment' => 'not_processed_by_this_request']),
                'created_at' => now(), 'updated_at' => now()]);
            W::event('enrollment', $enrollment, 'workforce.'.$a->id, 'requested');

            return ['id' => $enrollment, 'status' => 'requested'];
        });

        return W::response($row, 201);
    }

    public function enrollments(Request $r)
    {
        $rows = DB::table('training_enrollments as e')->join('training_courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.account_id', W::actor($r)->id)->orderByDesc('e.id')->paginate(20,
                ['e.id', 'e.course_id', 'c.title', 'c.delivery', 'c.starts_at', 'e.status', 'e.progress',
                    'e.certificate_document_id', 'e.completion_evidence_sha256', 'e.details']);
        $rows->setCollection($rows->getCollection()->map(function ($e) {
            $e->details = W::unpack($e->details);
            if ($e->starts_at) {
                $e->starts_at = \Carbon\Carbon::parse($e->starts_at, 'UTC')->toIso8601String();
            }
            if ($e->status !== 'completed') {
                $e->certificate_document_id = null;
            }
            $e->completion_verified = $e->status === 'completed' && ! empty($e->completion_evidence_sha256);
            unset($e->completion_evidence_sha256);

            return $e;
        }));

        return W::response($rows);
    }

    public function cancelEnrollment(Request $r, int $id)
    {
        $a = W::actor($r);
        DB::transaction(function () use ($a, $id) {
            $e = DB::table('training_enrollments')->where('id', $id)->where('account_id', $a->id)->lockForUpdate()->first();
            abort_unless($e, 404);
            abort_unless(in_array($e->status, ['requested', 'confirmed'], true), 409);
            DB::table('training_enrollments')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            W::event('enrollment', $id, 'workforce.'.$a->id, 'cancelled');
        });

        return W::response(['cancelled' => true]);
    }

    public function requestTraining(Request $r)
    {
        $a = W::actor($r);
        $a->load('employer');
        abort_unless($a->account_type === 'employer' && $a->employer, 403);
        $d = $r->validate(['topic' => 'required|string|min:3|max:150', 'objectives' => 'required|string|min:20|max:4000',
            'participants' => 'required|integer|min:1|max:10000', 'location' => 'required|string|max:200',
            'preferred_date' => 'required|date_format:Y-m-d|after:today',
            'delivery' => ['required', Rule::in(['onsite', 'virtual', 'hybrid'])],
            'contact_name' => 'required|string|max:120', 'contact_phone' => 'required|string|regex:/^[+0-9 ()-]{7,30}$/',
            'requirements' => 'nullable|string|max:2000']);
        $id = DB::transaction(function () use ($a, $d) {
            $id = DB::table('training_organization_requests')->insertGetId(['account_id' => $a->id, 'topic' => $d['topic'],
                'participants' => $d['participants'], 'delivery' => $d['delivery'], 'preferred_date' => $d['preferred_date'],
                'details' => W::pack(['organization' => $a->employer->organization_name, 'objectives' => $d['objectives'],
                    'location' => $d['location'], 'contact_name' => $d['contact_name'], 'contact_phone' => $d['contact_phone'],
                    'requirements' => $d['requirements'] ?? '']), 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);
            W::event('training_request', $id, 'workforce.'.$a->id, 'submitted');

            return $id;
        });

        return W::response(['id' => $id, 'status' => 'submitted'], 201);
    }

    public function requests(Request $r)
    {
        $rows = DB::table('training_organization_requests')->where('account_id', W::actor($r)->id)->orderByDesc('id')->paginate(20);
        $rows->setCollection($rows->getCollection()->map(function ($x) {
            $x->details = W::unpack($x->details);

            return $x;
        }));

        return W::response($rows);
    }

    public function requestResponse(Request $r, int $id)
    {
        $a = W::actor($r);
        $d = $r->validate(['accepted' => 'required|boolean']);
        DB::transaction(function () use ($a, $d, $id) {
            $x = DB::table('training_organization_requests')->where('id', $id)->where('account_id', $a->id)->lockForUpdate()->first();
            abort_unless($x, 404);
            abort_unless($x->status === 'quoted', 409);
            $details = W::unpack($x->details);
            abort_unless(! empty($details['quote_expires']) && $details['quote_expires'] >= today()->toDateString(), 409);
            $next = $d['accepted'] ? 'accepted' : 'cancelled';
            DB::table('training_organization_requests')->where('id', $id)->update(['status' => $next, 'updated_at' => now()]);
            W::event('training_request', $id, 'workforce.'.$a->id, $next);
        });

        return W::response(['recorded' => true]);
    }

    public function credentials(Request $r, int $candidateId)
    {
        $a = W::actor($r);
        $p = \App\Models\WorkforceCandidate::findOrFail($candidateId);
        if ($p->account_id !== $a->id) {
            W::employer($a);
            abort_unless($p->consent_to_list && $p->available_for_work && $p->verification_status === 'verified'
                && DB::table('career_portfolios')->where('account_id', $p->account_id)->where('share_training', true)->exists(), 404);
            W::eligible(\App\Models\WorkforceAccount::findOrFail($p->account_id));
        }

        return W::response(DB::table('training_enrollments as e')->join('training_courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.account_id', $p->account_id)->where('e.status', 'completed')->whereNotNull('e.completion_evidence_sha256')
            ->get(['c.title', 'c.category', 'e.updated_at as completion_recorded_at']));
    }
}
