@extends('layout.mainlayout_admin',['activePage' => 'workforce'])

@section('title',__('Workforce verification'))

@section('content')

<section class="section">

<p><a href="{{ route('career.manage') }}">Recruitment and training operations</a></p>
<h1>{{ __('Workforce verification') }}</h1>

<p>Check identity, email ownership and training or company registration against independent evidence. Verification is never automatic. Store evidence in the approved secure records system and enter its SHA256 reference below.</p>

<a href="{{ route('workforce.review',['kind'=>'candidate']) }}">Candidates</a> |

<a href="{{ route('workforce.review',['kind'=>'employer']) }}">Employers</a>

@if(session('status'))<p role="status">{{ session('status') }}</p>@endif

@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

<form method="get"><input type="hidden" name="kind" value="{{ $kind }}"><label>Account reference <input type="number" min="1" name="account_id" value="{{ request('account_id') }}"></label><button>Filter profiles</button></form>
@foreach($profiles as $profile)

<div class="card"><div class="card-body">

<h2>{{ $profile->account->name ?? 'Unavailable account' }} — #{{ $profile->id }}</h2>

<p>{{ $profile->account->email ?? '' }} | {{ $profile->verification_status }}</p>

@if($kind==='candidate')

<p>{{ $profile->career_stage }} | {{ $profile->institution }} | {{ $profile->qualification }} | {{ $profile->desired_role }}</p>

<p>{{ $profile->city }}, {{ $profile->state }} | Licence: {{ $profile->licence_status }}</p>

@else

<p>{{ $profile->organization_name }} | {{ $profile->organization_type }} | {{ $profile->registration_number }} | {{ $profile->state }}</p>

@endif

@if(!empty($profile->career_portfolio))<details><summary>Self-reported education, experience and skills — verify independently</summary>
@foreach($profile->career_portfolio as $key=>$value)<h3>{{ $key }}</h3>
@if(is_array($value))@foreach($value as $entry)<p>@if(is_array($entry))@foreach($entry as $label=>$item){{ $label }}: {{ is_scalar($item)?$item:'' }}; @endforeach @else {{ is_scalar($entry)?$entry:'' }} @endif</p>@endforeach @else <p>{{ is_scalar($value)?$value:'' }}</p>@endif
@endforeach</details>@endif
<form method="post" action="{{ url('workforce/review') }}">@csrf

<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="profile_id" value="{{ $profile->id }}">

<label>Decision <select name="decision" required><option value="">Select decision</option><option value="verified">Verified</option><option value="rejected">Rejected</option><option value="revoked">Revoked</option></select></label>

<label>Evidence SHA256 <input name="evidence_sha256" pattern="[a-f0-9]{64}" maxlength="64" required></label>

<label><input type="checkbox" name="email_checked" value="1"> Email ownership independently checked</label>

@if($kind==='candidate')

<label>Licence evidence SHA256 (required for regulated roles) <input name="licence_evidence_sha256" pattern="[a-f0-9]{64}" maxlength="64"></label>

<label>Licence expires <input type="date" name="licence_expires"></label>

@endif

<button type="submit" class="btn btn-primary">Record review decision</button>

</form></div></div>

@endforeach

{{ $profiles->links() }}

</section>

@endsection

