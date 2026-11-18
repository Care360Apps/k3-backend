<?php

namespace App\Http\Controllers;

use App\Services\CareerDocumentStorage;
use App\Services\CareerWorkflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CareerAdministrationController extends Controller
{
    private function authorizeArea(string $ability): void
    {
        abort_unless(Gate::allows($ability), 403);
        abort_unless(config('career.enabled', false), 503);
    }

    public function index(Request $r)
    {
        abort_unless(Gate::allows('workforce_review') || Gate::allows('training_manage'), 403);
        $area = $r->query('area', 'jobs');
        $map = ['jobs' => ['career_jobs', 'workforce_review'], 'documents' => ['career_documents', 'workforce_review'],
            'privacy' => ['career_privacy_requests', 'workforce_review'],
            'recruiters' => ['career_recruiters', 'workforce_review'], 'reports' => ['workforce_reports', 'workforce_review'], 'courses' => ['training_courses', 'training_manage'], 'enrollments' => ['training_enrollments', 'training_manage'],
            'requests' => ['training_organization_requests', 'training_manage']];
        abort_unless(isset($map[$area]), 404);
        abort_unless(Gate::allows($map[$area][1]), 403);
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable($map[$area][0]), 503);
        $rows = DB::table($map[$area][0])->orderByDesc('id')->paginate(20)->withQueryString();
        foreach ($rows as $row) {
            if (isset($row->details)) {
                $row->details = W::unpack($row->details);
            }
            if (isset($row->storage_name)) {
                unset($row->storage_name);
            }
            if ($area === 'recruiters') {
                $row->staff = DB::table('workforce_accounts')->where('id', $row->recruiter_account_id)->first(['name', 'email']);
                $row->organization = DB::table('workforce_employers')->where('account_id', $row->owner_account_id)->first(['organization_name', 'verification_status']);
            }
            if ($area === 'reports') {
                $row->messages = DB::table('workforce_messages')->where('invitation_id', $row->invitation_id)->orderByDesc('id')->limit(20)->get()->map(fn ($m) => ['sender' => $m->sender_account_id, 'body' => W::unpack($m->body_encrypted)['body'], 'created_at' => $m->created_at]);
            }
        }

        return view('superAdmin.workforce.operations', compact('area', 'rows'));
    }

    public function fulfillPrivacy(Request $r, int $id, \App\Services\CareerErasureService $service)
    {
        abort_unless(Gate::allows('workforce_review'), 403);
        $d = $r->validate(['policy_reference' => 'required|regex:/^[A-Za-z0-9._-]{1,80}$/',
            'review_evidence_sha256' => 'required|regex:/^[a-f0-9]{64}$/', 'review_completed' => 'accepted']);
        try {
            $service->fulfill($id, 'admin.'.auth()->id(), $d['policy_reference'], $d['review_evidence_sha256']);
        } catch (\RuntimeException $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }

return back()->withErrors(['privacy' => 'Erasure is not complete. Review private storage and retry this request.']);
        }

        return back()->withStatus('Direct career personal fields and files erased. Limited workflow references and any separately reviewed lawful retention are described by the approved policy. Clinical accounts were not modified.');
    }

    public function reviewRecruiter(Request $r, int $id)
    {
        $this->authorizeArea('workforce_review');
        $d = $r->validate(['status' => ['required', Rule::in(['approved', 'rejected', 'revoked'])],
            'email_checked' => 'nullable|accepted', 'evidence_sha256' => 'required|regex:/^[a-f0-9]{64}$/']);
        DB::transaction(function () use ($d, $id) {
            $x = DB::table('career_recruiters')->where('id', $id)->lockForUpdate()->first();
            abort_unless($x, 404);
            if ($d['status'] === 'approved') {
                abort_unless($x->status === 'pending_review' && ! empty($d['email_checked']), 422);
                W::owner(\App\Models\WorkforceAccount::findOrFail($x->owner_account_id));
                DB::table('workforce_accounts')->where('id', $x->recruiter_account_id)->whereNull('email_verified_at')->update(['email_verified_at' => now(), 'updated_at' => now()]);
            }
            DB::table('career_recruiters')->where('id', $id)->update(['status' => $d['status'], 'reviewer' => 'admin.'.auth()->id(),
                'evidence_sha256' => $d['evidence_sha256'], 'accepted_at' => null, 'updated_at' => now()]);
            W::event('recruiter', $id, 'admin.'.auth()->id(), $d['status']);
            W::notify($x->recruiter_account_id, 'recruiter_reviewed', $id);
        });

        return back()->withStatus('Recruiter authority review recorded. Staff must accept approved access before recruitment is enabled.');
    }

    public function resolveReport(Request $r, int $id)
    {
        $this->authorizeArea('workforce_review');
        $d = $r->validate(['status' => ['required', Rule::in(['resolved', 'dismissed'])], 'note' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($d, $id) {
            $report = DB::table('workforce_reports')->where('id', $id)->lockForUpdate()->first();
            abort_unless($report, 404);
            abort_unless($report->status === 'open', 409);
            DB::table('workforce_reports')->where('id', $id)->update(['status' => $d['status']]);
            W::event('recruitment_report', $id, 'admin.'.auth()->id(), $d['status'], ['note' => $d['note']]);
            W::notify($report->reporter_account_id, 'report_reviewed', $id);
        });

        return back()->withStatus('Report review recorded. Apply account restrictions separately if required.');
    }

    public function moderateJob(Request $r, int $id)
    {
        $this->authorizeArea('workforce_review');
        $d = $r->validate(['status' => ['required', Rule::in(['published', 'rejected', 'closed'])],
            'note' => 'required|string|min:5|max:1500']);
        DB::transaction(function () use ($d, $id) {
            $j = DB::table('career_jobs')->where('id', $id)->lockForUpdate()->first();
            abort_unless($j, 404);
            if ($d['status'] === 'published') {
                W::owner(\App\Models\WorkforceAccount::findOrFail($j->employer_account_id));
                abort_unless($j->closes_on >= today()->toDateString() && in_array($j->status, ['pending', 'published'], true), 409);
            }
            DB::table('career_jobs')->where('id', $id)->update(['status' => $d['status'], 'updated_at' => now()]);
            W::event('job', $id, 'admin.'.auth()->id(), 'moderated', ['status' => $d['status'], 'note' => $d['note']]);
            W::notify($j->employer_account_id, 'job_moderated', $id);
        });

        return back()->withStatus('Job moderation recorded.');
    }

    public function reviewDocument(Request $r, int $id)
    {
        $this->authorizeArea('workforce_review');
        $d = $r->validate(['status' => ['required', Rule::in(['verified', 'rejected'])],
            'note' => 'required|string|min:5|max:1500']);
        DB::transaction(function () use ($d, $id) {
            $doc = DB::table('career_documents')->where('id', $id)->lockForUpdate()->first();
            abort_unless($doc, 404);
            abort_unless($doc->kind !== 'certificate', 403);
            DB::table('career_documents')->where('id', $id)->update(['review_status' => $d['status'], 'updated_at' => now()]);
            W::event('document', $id, 'admin.'.auth()->id(), 'reviewed', ['status' => $d['status'], 'note' => $d['note']]);
            W::notify($doc->account_id, 'document_reviewed', $id);
        });

        return back()->withStatus('Document review recorded. Profile/license verification remains a separate decision.');
    }

    public function downloadDocument(int $id, CareerDocumentStorage $storage)
    {
        $this->authorizeArea('workforce_review');
        $d = DB::table('career_documents')->find($id);
        abort_unless($d && $d->kind !== 'certificate', 404);
        W::event('document', $id, 'admin.'.auth()->id(), 'downloaded');

        return $storage->read($d);
    }

    public function saveCourse(Request $r)
    {
        $this->authorizeArea('training_manage');
        $d = $r->validate(['legacy_course_id' => 'nullable|integer|exists:course,id', 'title' => 'required|string|min:3|max:150',
            'category' => 'required|string|max:80', 'description' => 'required|string|min:20|max:6000',
            'eligibility' => 'required|string|max:2000', 'delivery' => ['required', Rule::in(['onsite', 'virtual', 'hybrid'])],
            'duration' => 'required|string|max:100', 'fee_information' => 'required|string|max:200',
            'website' => ['nullable', 'url', 'max:500', function ($attribute, $value, $fail) {
                $u = parse_url($value);
                if (! is_array($u) || ($u['scheme'] ?? '') !== 'https'
                    || ! in_array(strtolower($u['host'] ?? ''), ['www.cnumedical.com', 'cnumedical.com'], true)
                    || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || isset($u['port'])) {
                    $fail('Use an approved HTTPS CNU course page.');
                }
            }], 'starts_at' => 'nullable|date_format:Y-m-d\TH:i:sP|after:now', 'capacity' => 'nullable|integer|min:1|max:10000',
            'published' => 'required|boolean', 'approved_information' => 'accepted']);
        unset($d['approved_information']);
        if (! empty($d['starts_at'])) {
            $d['starts_at'] = \Carbon\Carbon::parse($d['starts_at'])->utc();
        }
        DB::transaction(function () use ($d) {
            $id = DB::table('training_courses')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]);
            W::event('course', $id, 'admin.'.auth()->id(), 'cohort_created', ['legacy_course_id' => $d['legacy_course_id'] ?? null]);
        });

        return back()->withStatus('Course/cohort created with CNU-approved information.');
    }

    public function publishCourse(Request $r, int $id)
    {
        $this->authorizeArea('training_manage');
        $d = $r->validate(['published' => 'required|boolean']);
        DB::transaction(function () use ($d, $id) {
            abort_unless(DB::table('training_courses')->where('id', $id)->lockForUpdate()->first(), 404);
            DB::table('training_courses')->where('id', $id)->update(['published' => $d['published'], 'updated_at' => now()]);
            W::event('course', $id, 'admin.'.auth()->id(), $d['published'] ? 'published' : 'unpublished');
        });

        return back()->withStatus('Course visibility updated. Existing enrollments remain accessible.');
    }

    public function enrollment(Request $r, int $id)
    {
        $this->authorizeArea('training_manage');
        $d = $r->validate(['status' => ['required', Rule::in(['confirmed', 'rejected', 'in_progress', 'completed', 'cancelled'])],
            'progress' => 'nullable|integer|min:0|max:99', 'note' => 'required|string|min:5|max:2000',
            'completion_evidence_sha256' => 'nullable|regex:/^[a-f0-9]{64}$/', 'certificate_document_id' => 'nullable|integer|min:1']);
        $initial = DB::table('training_enrollments')->find($id);
        abort_unless($initial, 404);
        DB::transaction(function () use ($d, $id, $initial) {
            $c = DB::table('training_courses')->where('id', $initial->course_id)->lockForUpdate()->first();
            $e = DB::table('training_enrollments')->where('id', $id)->lockForUpdate()->first();
            $allowed = ['requested' => ['confirmed', 'rejected', 'cancelled'], 'confirmed' => ['in_progress', 'cancelled'],
                'in_progress' => ['in_progress', 'completed', 'cancelled']];
            abort_unless(in_array($d['status'], $allowed[$e->status] ?? [], true), 409);
            if ($d['status'] === 'confirmed') {
                abort_unless($c->published, 409);
                $occupied = DB::table('training_enrollments')->where('course_id', $c->id)->whereIn('status', ['confirmed', 'in_progress', 'completed'])->count();
                abort_if($c->capacity !== null && $occupied >= (int) $c->capacity, 409);
            }
            $changes = ['status' => $d['status'], 'updated_at' => now()];
            if ($d['status'] === 'in_progress') {
                abort_unless(isset($d['progress']) && $d['progress'] >= $e->progress, 422);
                $changes['progress'] = $d['progress'];
            }
            if ($d['status'] === 'completed') {
                abort_unless(! empty($d['completion_evidence_sha256']), 422);
                $changes['progress'] = 100;
                $changes['completion_evidence_sha256'] = $d['completion_evidence_sha256'];
                $certificate = $d['certificate_document_id'] ?? $e->certificate_document_id;
                if ($certificate) {
                    abort_unless(DB::table('career_documents')->where('id', $certificate)->where('account_id', $e->account_id)
                        ->where('kind', 'certificate')->where('review_status', 'verified')->exists(), 404);
                    $changes['certificate_document_id'] = $certificate;
                }
            }
            $details = W::unpack($e->details);
            $details['latest_admin_note'] = $d['note'];
            $changes['details'] = W::pack($details);
            DB::table('training_enrollments')->where('id', $id)->update($changes);
            W::event('enrollment', $id, 'admin.'.auth()->id(), $d['status'], ['note' => $d['note']]);
            W::notify($e->account_id, 'training_status', $id);
        });

        return back()->withStatus('Enrollment decision recorded. Completion is administrator verified, not LMS synchronization.');
    }

    public function certificate(Request $r, int $id, CareerDocumentStorage $storage)
    {
        $this->authorizeArea('training_manage');
        $e = DB::table('training_enrollments')->find($id);
        abort_unless($e, 404);
        abort_unless(in_array($e->status, ['confirmed', 'in_progress'], true), 409);
        $r->validate(['file' => 'required|file|max:5120|mimetypes:application/pdf,image/jpeg,image/png']);
        $saved = $storage->store($r->file('file'));
        try {
            DB::transaction(function () use ($e, $saved) {
                $locked = DB::table('training_enrollments')->where('id', $e->id)->lockForUpdate()->first();
                abort_unless(in_array($locked->status, ['confirmed', 'in_progress'], true), 409);
                $doc = DB::table('career_documents')->insertGetId($saved + ['account_id' => $e->account_id, 'kind' => 'certificate',
                    'review_status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('training_enrollments')->where('id', $e->id)->update(['certificate_document_id' => $doc, 'updated_at' => now()]);
                W::event('document', $doc, 'admin.'.auth()->id(), 'certificate_uploaded', ['enrollment_id' => $e->id]);
            });
        } catch (\Throwable $x) {
            $storage->remove($saved['storage_name']);
            throw $x;
        }

        return back()->withStatus('Private certificate uploaded. Its document ID is listed in the learner documents; attach it when recording completion.');
    }

    public function organizationRequest(Request $r, int $id)
    {
        $this->authorizeArea('training_manage');
        $d = $r->validate(['status' => ['required', Rule::in(['quoted', 'rejected', 'scheduled', 'in_progress', 'completed', 'cancelled'])],
            'note' => 'required|string|min:5|max:2000', 'amount_minor' => 'nullable|integer|min:0|max:1000000000',
            'currency' => ['nullable', Rule::in(['NGN', 'USD'])], 'quote_expires' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'starts_at' => 'nullable|date_format:Y-m-d\TH:i:sP|after:now', 'facilitator' => 'nullable|string|max:150',
            'completion_evidence_sha256' => 'nullable|regex:/^[a-f0-9]{64}$/']);
        DB::transaction(function () use ($d, $id) {
            $x = DB::table('training_organization_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($x, 404);
            $allowed = ['submitted' => ['quoted', 'rejected', 'cancelled'], 'quoted' => ['quoted', 'cancelled'],
                'accepted' => ['scheduled', 'cancelled'], 'scheduled' => ['in_progress', 'cancelled'], 'in_progress' => ['completed', 'cancelled']];
            abort_unless(in_array($d['status'], $allowed[$x->status] ?? [], true), 409);
            $details = W::unpack($x->details);
            if ($d['status'] === 'quoted') {
                abort_unless(isset($d['amount_minor'],$d['currency'],$d['quote_expires']), 422);
                foreach (['amount_minor', 'currency', 'quote_expires'] as $key) {
                    $details[$key] = $d[$key];
                }
            }
            if ($d['status'] === 'scheduled') {
                abort_unless(! empty($d['starts_at']) && ! empty($d['facilitator']), 422);
                $details['starts_at'] = \Carbon\Carbon::parse($d['starts_at'])->utc()->toIso8601String();
                $details['facilitator'] = $d['facilitator'];
            }
            if ($d['status'] === 'completed') {
                abort_unless(! empty($d['completion_evidence_sha256']), 422);
                $details['completion_evidence_sha256'] = $d['completion_evidence_sha256'];
            }
            $details['latest_admin_note'] = $d['note'];
            DB::table('training_organization_requests')->where('id', $id)->update(['status' => $d['status'], 'details' => W::pack($details), 'updated_at' => now()]);
            W::event('training_request', $id, 'admin.'.auth()->id(), $d['status'], ['note' => $d['note']]);
            W::notify($x->account_id, 'organization_training_status', $id);
        });

        return back()->withStatus('Organization training update recorded. Quotation acceptance does not charge a payment.');
    }
}
