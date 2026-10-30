@extends('layout.mainlayout_admin',['activePage' => 'provider'])

@section('title',__('Show Provider'))
@section('content')
<section class="section">
    @include('layout.breadcrumb',[
        'title' => __('Provider Details'),
        'url' => url('provider'),
        'urlTitle' => __('Providers'),
    ])
    <div class="card profile-widget mt-5">
        <div class="profile-widget-header">
            <a href="{{ $provider->fullImage }}" data-fancybox="gallery2">
                <img alt="image" src="{{ $provider->fullImage }}" class="rounded-circle profile-widget-picture">
            </a>
            <div class="btn-group mb-2 dropleft float-right p-3">
                <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                {{ __('More Details') }}
                </button>
                <div class="dropdown-menu" x-placement="bottom-start">
                    <a class="dropdown-item" href="{{ url('provider/'.$provider->id.'/'.Str::slug($provider->name).'/schedule') }}">{{ __('Schedule Timing') }}</a>
                    <a class="dropdown-item" href="{{ url('provider/'.$provider->id.'/'.Str::slug($provider->name).'/finance') }}">{{ __('Finance Details') }}</a>
                </div>
            </div>
        </div>
        <div class="profile-widget-description">
            <div class="profile-widget-name">{{ $provider->name }}
                <div class="text-muted d-inline font-weight-normal">
                    @if (isset($provider->providerType))
                    <div class="slash"></div>
                    {{ $provider->providerType->name }}
                    @endif
                </div>
            </div>
            {{ $provider->desc }}
        </div>
    </div>
    @include('superAdmin.doctor.certification_documents', ['documents' => $provider->documents])
    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <h6>{{ __('Patient Appointments (Home Visits)') }}</h6>
            @include('superAdmin.auth.exportButtons')
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="w-100 display table datatable">
                    <thead>
                        <tr>
                            <th><input name="select_all" value="1" id="master" type="checkbox" /><label for="master"></label></th>
                            <th>#</th>
                            <th>{{__('appointment id')}}</th>
                            <th>{{__('Report or patient image')}}</th>
                            <th>{{__('amount')}}</th>
                            <th>{{__('date')}}</th>
                            <th>{{__('payment status')}}</th>
                            <th>{{__('status')}}</th>
                            <th>{{__('view appointment')}}</th>
                        </tr>
                    </thead>
                    <tbody class="text-center">
                        @foreach ($totalAppointments as $appointment)
                            <tr>
                                <td><input type="checkbox" name="id[]" value="{{$appointment->id}}" id="{{$appointment->id}}" data-id="{{ $appointment->id }}" class="sub_chk"><label for="{{$appointment->id}}"></label></td>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $appointment->appointment_id }}</td>
                                <td>
                                    @if ($appointment->report_image != null)
                                        @foreach (is_array($appointment->report_image) ? $appointment->report_image : json_decode($appointment->report_image ?? '[]', true) ?? [] as $item)
                                            <a href="{{ is_string($item) ? url('images/upload/'.$item) : $item }}" data-fancybox="gallery2">
                                                <img src="{{ is_string($item) ? url('images/upload/'.$item) : $item }}" alt="Report" width="50px" height="50px">
                                            </a>
                                        @endforeach
                                    @else
                                        {{__('Image Not available')}}
                                    @endif
                                </td>
                                <td>{{ $currency }}{{ $appointment->amount }}</td>
                                <td>{{ $appointment->date }}<span class="d-block text-info">{{ $appointment->time }}</span></td>
                                <td>
                                    @if ($appointment->payment_status == 1)
                                        <span class="btn btn-sm btn-success">{{__('Paid')}}</span>
                                    @else
                                        <span class="btn btn-sm btn-danger">{{__('Remaining')}}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($appointment->appointment_status == 'pending' || $appointment->appointment_status == 'PENDING')
                                        <span class="badge badge-pill bg-warning-light">{{__('Pending')}}</span>
                                    @endif
                                    @if($appointment->appointment_status == 'approve' || $appointment->appointment_status == 'APPROVE')
                                        <span class="badge badge-pill bg-success-light">{{__('Approved')}}</span>
                                    @endif
                                    @if($appointment->appointment_status == 'cancel' || $appointment->appointment_status == 'CANCEL')
                                        <span class="badge badge-pill bg-danger-light">{{__('Cancelled')}}</span>
                                    @endif
                                    @if($appointment->appointment_status == 'complete' || $appointment->appointment_status == 'COMPLETE')
                                        <span class="badge badge-pill bg-default-light">{{__('Completed')}}</span>
                                    @endif
                                </td>
                                <td><a href="#edit_specialities_details" onclick="show_appointment({{$appointment->id}})" data-toggle="modal" class="text-info">{{__('View')}}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="edit_specialities_details" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{__("Appointment")}}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
            </div>
            <div class="modal-body">
                <table class="table">
                    <tr><td>{{__('appointment Id')}}</td><td class="appointment_id"></td></tr>
                    <tr><td>{{__('Provider name')}}</td><td class="doctor_name"></td></tr>
                    <tr><td>{{__('patient name')}}</td><td class="patient_name"></td></tr>
                    <tr><td>{{__('patient address')}}</td><td class="patient_address"></td></tr>
                    <tr><td>{{__('patient age')}}</td><td class="patient_age"></td></tr>
                    <tr><td>{{__('amount')}}</td><td class="amount"></td></tr>
                    <tr><td>{{__('date')}}</td><td class="date"></td></tr>
                    <tr><td>{{__('time')}}</td><td class="time"></td></tr>
                    <tr><td>{{__('payment status')}}</td><td class="payment_status"></td></tr>
                    <tr><td>{{__('payment type')}}</td><td class="payment_type"></td></tr>
                    <tr><td>{{__('illness information')}}</td><td class="illness_info"></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{__('Close')}}</button>
            </div>
        </div>
    </div>
</div>
@endsection
