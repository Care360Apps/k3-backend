@extends('layout.mainlayout_admin',['activePage'=>'test_report'])
@section('title',__('Doctor investigation requests'))
@section('content')
<section class="section"><h1>{{ __('Doctor investigation requests') }}</h1>
<p>Requests do not create a test booking or change prices or payments. Verify the patient, arrange the normal booking and link it below. Review the uploaded result and release it through the normal laboratory workflow.</p>
<p><strong>Critical results:</strong> follow your approved urgent direct-contact procedure. This page records flags and acknowledgements; it does not guarantee that a notification reaches a doctor. Do not wait for the app when urgent escalation is needed.</p>
<a href="{{ url('test_reports') }}">Laboratory bookings and result uploads</a>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@foreach($orders as $order)
<div class="card"><div class="card-body">
<h2>#{{ $order->id }}: {{ $order->payload['title'] }}</h2>
<p>Appointment #{{ $order->appointment_id }} | Doctor record #{{ $order->doctor_id }} | {{ $order->urgency }} | {{ $order->status }}</p>
<p>Patient: {{ $order->payload['patient_name'] ?? 'Identity unavailable' }} | Age: {{ $order->payload['patient_age'] ?? 'Unavailable' }}</p>
<p style="white-space:pre-wrap">{{ $order->payload['details'] }}</p>
<p>Referring clinician contact: {{ $order->payload['clinical_contact'] ?? 'Confirm through your clinical directory' }}</p>
@if(!$order->report_id && $order->status==='requested')
<form method="post" action="{{ url('lab_clinical_requests/link') }}">@csrf
<input type="hidden" name="order_id" value="{{ $order->id }}">
<label>Existing laboratory booking record ID <input type="number" name="report_id" min="1" required></label>
<label><input type="checkbox" name="identity_checked" value="1" required> I verified the patient identity against the request and booking.</label>
<button type="submit">Link verified booking</button></form>
@elseif($order->report_id)
<p>Linked laboratory booking #{{ $order->report_id }}</p>
@if($flags->has($order->id))
@php($flag=$flags->get($order->id))
<p><strong>{{ $flag->acknowledged_at ? 'Clinician acknowledged' : 'CRITICAL: awaiting clinician acknowledgement' }}</strong></p>
<form method="post" action="{{ url('lab_clinical_requests/contact') }}">@csrf
<input type="hidden" name="order_id" value="{{ $order->id }}">
<label>Additional direct contact / correction <textarea name="note" maxlength="2000" required></textarea></label>
<button type="submit">Append contact update</button></form>
@foreach($events->get($order->id, collect()) as $event)
<p>{{ $event->created_at }} | User #{{ $event->actor_user_id }} | {{ str_replace('_',' ', $event->action) }} @if($event->details): {{ $event->details }}@endif</p>
@endforeach
<p>{{ $flag->reason }}</p><p>Initial contact attempt: {{ $flag->contact_attempt }}</p>
@if($flag->acknowledged_at)<p>Acknowledged by user #{{ $flag->acknowledged_by }} at {{ $flag->acknowledged_at }}. This is not proof of clinical resolution.</p><p>{{ $flag->acknowledgement }}</p>@endif
@elseif(($reports->get($order->report_id)?->workflow_status ?? '') === 'released')
<form method="post" action="{{ url('lab_clinical_requests/critical') }}">@csrf
<input type="hidden" name="order_id" value="{{ $order->id }}">
<label>Clinician-reviewed critical concern <textarea name="reason" maxlength="2000" required></textarea></label>
<label>Direct contact attempt and outcome <textarea name="contact_attempt" maxlength="2000" required></textarea></label>
<p>Release the reviewed result first. Continue urgent direct-contact escalation according to your procedure.</p>
<button type="submit">Record critical flag and contact attempt</button></form>
@else
<p>The linked booking is awaiting result review and release. Use the laboratory bookings page to complete it. Follow urgent direct-contact procedures without waiting for this app.</p>
@endif
@endif
</div></div>
@endforeach
{{ $orders->links() }}
</section>
@endsection
