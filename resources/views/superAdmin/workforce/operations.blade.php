@extends('layout.mainlayout_admin',['activePage'=>'workforce'])
@section('title','Career and CNU training operations')
@section('content')
<section class="section">
<h1>Career and CNU training operations</h1>
<nav>
@can('workforce_review')<a href="?area=privacy">Career data deletion</a> | <a href="?area=recruiters">Recruiter verification</a> | <a href="?area=reports">Abuse reports</a> | <a href="?area=jobs">Job moderation</a> | <a href="?area=documents">Private verification documents</a> | <a href="{{ route('workforce.review') }}">Profile verification</a> |@endcan
@can('training_manage')<a href="?area=courses">Course cohorts</a> | <a href="?area=enrollments">Enrollments</a> | <a href="?area=requests">Organization training</a>@endcan
</nav>
@unless(config('career.enabled'))<p role="alert">Recruitment and training writes are disabled. Account privacy actions have a separate approval policy.</p>@endunless
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($area==='courses')
<div class="card"><div class="card-body">
<h2>Create a CNU-approved course cohort</h2>
<p>Existing catalogue management remains at <a href="{{ url('course') }}">Courses</a>. Link its course ID below where applicable. This is a cohort/enrollment workflow, not a replacement learning platform. Do not claim unverified accreditation or CPD credits.</p>
<form method="post" action="{{ url('career/manage/courses') }}">@csrf
<label>Existing course ID (optional) <input type="number" min="1" name="legacy_course_id"></label>
<label>Title <input name="title" maxlength="150" required></label>
<label>Category/profession <input name="category" maxlength="80" required></label>
<label>Description <textarea name="description" minlength="20" maxlength="6000" required></textarea></label>
<label>Eligibility <textarea name="eligibility" maxlength="2000" required></textarea></label>
<label>Delivery <select name="delivery"><option>onsite</option><option>virtual</option><option>hybrid</option></select></label>
<label>Duration <input name="duration" maxlength="100" required></label>
<label>Approved fee information and currency <input name="fee_information" maxlength="200" required></label>
<label>Approved CNU HTTPS course URL <input type="url" name="website" maxlength="500"></label>
<label>Starts at (ISO 8601 including UTC offset) <input name="starts_at" placeholder="2027-01-15T09:00:00+01:00"></label>
<label>Cohort capacity <input type="number" name="capacity" min="1" max="10000"></label>
<label>Visibility <select name="published"><option value="0">Draft</option><option value="1">Published</option></select></label>
<label><input type="checkbox" name="approved_information" value="1" required> CNU has approved this course information and enrollment handling</label>
<button type="submit">Create cohort</button></form>
</div></div>
@endif
@foreach($rows as $row)
<article class="card"><div class="card-body">
<h2>#{{ $row->id }} {{ $row->title ?? $row->topic ?? $row->kind ?? '' }}</h2>
@if($area==='privacy')
<p>Career account #{{ $row->account_id }} | {{ $row->status }} | requested {{ $row->created_at }}</p>
<p>Review the approved retention/deletion policy, related disputes and any third-party lawful retention before authorizing erasure. This erases direct career personal fields and files, revokes sessions and retains limited de-identified workflow references. Patient/Plus clinical accounts are separate.</p>
@if(in_array($row->status,['requested','processing']))
<form method="post" action="{{ url('career/manage/privacy/'.$row->id) }}">@csrf
<label>Approved policy reference (no personal data) <input name="policy_reference" pattern="[A-Za-z0-9._-]{1,80}" required></label>
<label>Review evidence SHA256 <input name="review_evidence_sha256" pattern="[a-f0-9]{64}" required></label>
<label><input type="checkbox" name="review_completed" value="1" required> Privacy review, lawful retention and related-party records addressed under approved policy</label><button>Perform career erasure / retry incomplete cleanup</button></form>
@endif
@elseif($area==='recruiters')
<p>Organization owner account #{{ $row->owner_account_id }} | Staff account #{{ $row->recruiter_account_id }} | {{ $row->status }}</p>
<p>{{ $row->staff->name ?? 'Unavailable staff account' }} | {{ $row->staff->email ?? '' }} | {{ $row->organization->organization_name ?? 'Unavailable organization' }} | {{ $row->organization->verification_status ?? 'not verified' }}</p>
<p>Check staff identity, email ownership and authority with the verified organization. This grants recruitment access only, never clinical privileges.</p>
<form method="post" action="{{ url('career/manage/recruiters/'.$row->id) }}">@csrf
<select name="status"><option value="approved">Approve invitation</option><option value="rejected">Reject</option><option value="revoked">Revoke</option></select>
<label>Evidence SHA256 <input name="evidence_sha256" pattern="[a-f0-9]{64}" maxlength="64" required></label>
<label><input type="checkbox" name="email_checked" value="1"> Staff email ownership independently checked</label><button>Record authority review</button></form>
@elseif($area==='reports')
<p>Invitation #{{ $row->invitation_id }} | Reporter account #{{ $row->reporter_account_id }} | {{ $row->reason }} | {{ $row->status }}</p>
<details><summary>Most recent 20 messages in this reported conversation</summary>@foreach($row->messages as $m)<p>Account #{{ $m['sender'] }} · {{ $m['created_at'] }}: {{ $m['body'] }}</p>@endforeach</details>
<form method="post" action="{{ url('career/manage/reports/'.$row->id) }}">@csrf
<select name="status"><option value="resolved">Resolved</option><option value="dismissed">Dismissed</option></select>
<label>Review and safeguarding action note <textarea name="note" minlength="10" maxlength="2000" required></textarea></label><button>Record review</button></form>
@elseif($area==='jobs')
<p>Employer account #{{ $row->employer_account_id }} | {{ $row->status }} | {{ $row->profession }} | {{ $row->state }} | closes {{ $row->closes_on }}</p>
<p>{{ $row->description }}</p><p>{{ $row->requirements }}</p>
<form method="post" action="{{ url('career/manage/jobs/'.$row->id) }}">@csrf
<label>Decision <select name="status"><option value="published">Publish</option><option value="rejected">Reject</option><option value="closed">Close</option></select></label>
<label>Review note <textarea name="note" minlength="5" maxlength="1500" required></textarea></label><button>Record decision</button></form>
@elseif($area==='documents')
<p>Account #{{ $row->account_id }} | {{ $row->kind }} | {{ $row->mime }} | {{ $row->bytes }} bytes | {{ $row->review_status }}</p>
@if($row->kind!=='certificate')
<p>Evidence SHA256: <code>{{ $row->sha256 }}</code></p><a href="{{ url('workforce/review') }}?kind={{ $row->kind==='organization'?'employer':'candidate' }}&amp;account_id={{ $row->account_id }}">Open profile verification</a> | <a href="{{ url('career/manage/documents/'.$row->id) }}">Download private document for review</a>
<form method="post" action="{{ url('career/manage/documents/'.$row->id) }}">@csrf
<select name="status"><option value="verified">Verified document</option><option value="rejected">Rejected document</option></select>
<label>Evidence/check note <textarea name="note" minlength="5" maxlength="1500" required></textarea></label><button>Record document review</button></form>
@endif
@elseif($area==='courses')
<p>{{ $row->category }} | {{ $row->delivery }} | {{ $row->duration }} | {{ $row->fee_information }}</p>
<p>{{ $row->description }}</p><p>{{ $row->eligibility }}</p>
<p>Existing catalogue course #{{ $row->legacy_course_id ?? 'not linked' }} | starts {{ $row->starts_at ?? 'by arrangement' }} | capacity {{ $row->capacity ?? 'not limited' }}</p>
<form method="post" action="{{ url('career/manage/courses/'.$row->id.'/publish') }}">@csrf
<select name="published"><option value="1" @selected($row->published)>Published</option><option value="0" @selected(!$row->published)>Hidden</option></select><button>Update visibility</button></form>
@elseif($area==='enrollments')
<p>Learner account #{{ $row->account_id }} | cohort #{{ $row->course_id }} | {{ $row->status }} | {{ $row->progress }}% administrator-recorded progress</p>
<p>{{ $row->details['latest_admin_note'] ?? '' }}</p>
<form method="post" enctype="multipart/form-data" action="{{ url('career/manage/enrollments/'.$row->id.'/certificate') }}">@csrf
<label>CNU-issued completion certificate (private; released only after completion) <input type="file" accept="application/pdf,image/png,image/jpeg" name="file" required></label><button>Upload certificate</button></form>
<form method="post" action="{{ url('career/manage/enrollments/'.$row->id) }}">@csrf
<label>Status <select name="status">@foreach(['confirmed','rejected','in_progress','completed','cancelled'] as $s)<option>{{ $s }}</option>@endforeach</select></label>
<label>Progress (0–99 for in progress) <input type="number" name="progress" min="0" max="99"></label>
<label>Completion evidence SHA256 <input name="completion_evidence_sha256" pattern="[a-f0-9]{64}" maxlength="64"></label>
<label>Optional private certificate document ID <input type="number" min="1" name="certificate_document_id" value="{{ $row->certificate_document_id }}"></label>
<label>Administrative note <textarea name="note" minlength="5" maxlength="2000" required></textarea></label><button>Record enrollment update</button></form>
@elseif($area==='requests')
<p>Organization account #{{ $row->account_id }} | {{ $row->status }} | {{ $row->participants }} participants | {{ $row->delivery }} | preferred {{ $row->preferred_date }}</p>
@foreach($row->details as $key=>$value)@if(is_scalar($value))<p>{{ $key }}: {{ $value }}</p>@endif @endforeach
<form method="post" action="{{ url('career/manage/requests/'.$row->id) }}">@csrf
<label>Status <select name="status">@foreach(['quoted','rejected','scheduled','in_progress','completed','cancelled'] as $s)<option>{{ $s }}</option>@endforeach</select></label>
<label>Quotation amount in minor units (kobo/cents) <input type="number" name="amount_minor" min="0" max="1000000000"></label>
<label>Currency <select name="currency"><option value="">Select</option><option>NGN</option><option>USD</option></select></label>
<label>Quote expires <input type="date" name="quote_expires"></label>
<label>Scheduled start, ISO 8601 with offset <input name="starts_at" placeholder="2027-01-15T09:00:00+01:00"></label>
<label>Assigned facilitator <input name="facilitator" maxlength="150"></label>
<label>Completion evidence SHA256 <input name="completion_evidence_sha256" pattern="[a-f0-9]{64}" maxlength="64"></label>
<label>Administrative note <textarea name="note" minlength="5" maxlength="2000" required></textarea></label><button>Record update</button></form>
@endif
</div></article>
@endforeach
{{ $rows->links() }}
</section>
@endsection
