@extends('layout.mainlayout_admin',['activePage' => 'provider_type'])

@section('title',__('Provider Types'))
@section('content')

<section class="section">
    @include('layout.breadcrumb',[
        'title' => __('Provider Types'),
    ])
    <div class="section-body">
        @if (session('status'))
            @include('superAdmin.auth.status',['status' => session('status')])
        @endif
        <div class="card">
            <div class="card-header w-100 text-right d-flex justify-content-between">
                @include('superAdmin.auth.exportButtons')
                @can('provider_type_add')
                    <a href="{{ url('provider_type/create') }}">{{ __('Add New') }}</a>
                @endcan
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="w-100 display table datatable">
                        <thead>
                            <tr>
                                <th>
                                    <input name="select_all" value="1" id="master" type="checkbox" />
                                    <label for="master"></label>
                                </th>
                                <th>#</th>
                                <th>{{__('Name')}}</th>
                                <th>{{__('Description')}}</th>
                                <th>{{__('Icon')}}</th>
                                <th>{{__('Status')}}</th>
                                @if (Gate::check('provider_type_edit') || Gate::check('provider_type_delete'))
                                    <th>{{__('Action')}}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($providerTypes as $type)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="id[]" value="{{$type->id}}" id="{{$type->id}}" data-id="{{ $type->id }}" class="sub_chk">
                                        <label for="{{$type->id}}"></label>
                                    </td>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $type->name }}</td>
                                    <td>{{ Str::limit($type->description ?? '-', 50) }}</td>
                                    <td>{{ $type->icon ?? '-' }}</td>
                                    <td>
                                        <label class="cursor-pointer">
                                            <input type="checkbox" id="status{{$type->id}}" class="custom-switch-input" name="status" onchange="change_status('provider_type',{{ $type->id }})" {{ $type->status == 1 ? 'checked' : '' }}>
                                            <span class="custom-switch-indicator"></span>
                                        </label>
                                    </td>
                                    @if (Gate::check('provider_type_edit') || Gate::check('provider_type_delete'))
                                        <td>
                                            @can('provider_type_edit')
                                            <a class="text-success" href="{{url('provider_type/'.$type->id.'/edit')}}">
                                                <i class="far fa-edit"></i>
                                            </a>
                                            @endcan
                                            @can('provider_type_delete')
                                            <a class="text-danger" href="javascript:void(0);" onclick="deleteData('provider_type',{{ $type->id }})">
                                                <i class="far fa-trash-alt"></i>
                                            </a>
                                            @endcan
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <input type="button" value="{{__('Delete Selected')}}" onclick="deleteAll('provider_type_all_delete')" class="btn btn-primary">
            </div>
        </div>
    </div>
</section>
@endsection
