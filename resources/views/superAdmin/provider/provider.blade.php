@extends('layout.mainlayout_admin',['activePage' => 'provider'])

@section('title',__('All Providers'))
@section('content')

<section class="section">
    @include('layout.breadcrumb',[
        'title' => __('Providers'),
    ])
    @if (session('status'))
        @include('superAdmin.auth.status',['status' => session('status')])
    @endif
    <div class="section_body">
        <div class="card">
            <div class="card-header w-100 text-right d-flex justify-content-between">
                @include('superAdmin.auth.exportButtons')
                <div class="d-flex justify-content-end">
                    @can('provider_add')
                        <a href="{{ url('provider/create') }}">{{ __('Add New') }}</a>
                    @endcan
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="datatable table table-hover table-center mb-0">
                        <thead>
                            <tr>
                                <th><input name="select_all" value="1" id="master" type="checkbox" /><label for="master"></label></th>
                                <th>#</th>
                                <th>{{__('Provider Name')}}</th>
                                <th>{{__('email')}}</th>
                                <th>{{__('Provider Type')}}</th>
                                <th>{{__('Based On')}}</th>
                                <th>{{__('Member Since')}}</th>
                                <th>{{__('Documents')}}</th>
                                <th>{{__('Status')}}</th>
                                @if (Gate::check('provider_edit') || Gate::check('provider_delete'))
                                    <th>{{__('Actions')}}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($providers as $provider)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="id[]" value="{{$provider->id}}" id="{{$provider->id}}" data-id="{{ $provider->id }}" class="sub_chk">
                                        <label for="{{$provider->id}}"></label>
                                    </td>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        <a href="{{ url('provider/'.$provider->id.'/'.Str::slug($provider->name).'/dashboard') }}" class="avatar avatar-sm mr-2">
                                            <img class="avatar-img rounded-circle" src="{{ $provider->fullImage }}" alt="Provider Image"></a>
                                        <a href="{{ url('provider/'.$provider->id.'/'.Str::slug($provider->name).'/dashboard') }}">{{ $provider->name }}</a>
                                    </td>
                                    <td><a href="mailto:{{$provider->user['email']}}"><span class="text_transform_none">{{ $provider->user['email'] }}</span></a></td>
                                    <td>{{ $provider->providerType->name ?? __('Not defined') }}</td>
                                    <td>{{ $provider->based_on }}</td>
                                    @php $since = explode(' , ', $provider->since); @endphp
                                    <td>{{ $since[0] ?? '' }}<br><small>{{ $since[1] ?? '' }}</small></td>
                                    @include('superAdmin.doctor.documents_count_cell', ['count' => $provider->documents_count, 'url' => url('provider/'.$provider->id.'/'.Str::slug($provider->name).'/dashboard')])
                                    <td>
                                        <label class="cursor-pointer">
                                            <input type="checkbox" id="status_1{{$provider->id}}" class="custom-switch-input" onchange="change_status('provider',{{ $provider->id }})" {{ $provider->status == 1 ? 'checked' : '' }}>
                                            <span class="custom-switch-indicator"></span>
                                        </label>
                                    </td>
                                    @if (Gate::check('provider_edit') || Gate::check('provider_delete'))
                                        <td>
                                            <a href="{{ url('provider/'.$provider->id.'/'.Str::slug($provider->name).'/dashboard') }}" class="text-info"><i class="far fa-eye"></i></a>
                                            @can('provider_edit')
                                            <a class="text-success" href="{{url('provider/'.$provider->id.'/edit')}}"><i class="far fa-edit"></i></a>
                                            @endcan
                                            @can('provider_delete')
                                            <a class="text-danger" href="javascript:void(0);" onclick="deleteData('provider',{{ $provider->id }})"><i class="far fa-trash-alt"></i></a>
                                            <a href="{{route('loginAsDoctor', $provider->user->id)}}" data-toggle="tooltip" data-placement="top" title="{{__('Login as')}}"><i class="fas fa-sign-in-alt"></i></a>
                                            @endcan
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card_fotter">
                <input type="button" value="{{__('Delete Selected')}}" onclick="deleteAll('provider_all_delete')" class="btn btn-primary">
            </div>
        </div>
    </div>
</section>
@endsection
