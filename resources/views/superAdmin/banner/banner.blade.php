@extends('layout.mainlayout_admin',['activePage' => 'banner'])

@section('title',__('All banner'))
@section('content')
<section class="section">
    @include('layout.breadcrumb',[
        'title' => __('Banner'),
    ])
    <div class="section_body">
        @if (session('status'))
            @include('superAdmin.auth.status',[
                'status' => session('status')])
            @endif
        <div class="card">
            <div class="card-header w-100 d-flex justify-content-between">
                @include('superAdmin.auth.exportButtons')
                @can('banner_add')
                    <a href="{{ url('banner/create') }}" class="btn btn-primary">{{ __('Add campaign') }}</a>
                @endcan
            </div>
            <div class="card-body">
                    <table class="w-100 display table datatable">
                        <thead>
                            <tr>
                                <th> # </th>
                                <th>{{ __('Campaign') }}</th>
                                <th>{{ __('Image') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th>{{ __('Schedule') }}</th>
                                <th>{{ __('Status') }}</th>
                                @if (Gate::check('banner_edit') || Gate::check('banner_delete'))
                                    <th> {{__('Action')}} </th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($banners as $banner)
                                <tr>
                                    <td>{{$loop->iteration}}</td>
                                    <td>
                                        <strong>{{ $banner->name }}</strong>
                                        <div class="small text-muted">{{ IlluminateSupportStr::limit($banner->description ?? '', 70) }}</div>
                                        <div class="small">{{ $banner->link }}</div>
                                    </td>
                                    <td>
                                        <a href="{{ route('banner.image.preview', $banner->id) }}" data-fancybox="gallery2">
                                            <img class="avatar-img rounded-circle" alt="User Image" src="{{ route('banner.image.preview', $banner->id) }}" height="50" width="50">
                                        </a>
                                    </td>
                                    <td>{{ $banner->is_sponsored ? __('Sponsored') : __('Partner') }}</td>
                                    <td>
                                        {{ optional($banner->starts_at)->format('Y-m-d') ?? __('Immediately') }}
                                        —
                                        {{ optional($banner->ends_at)->format('Y-m-d') ?? __('No end date') }}
                                    </td>
                                    <td>
                                        <label class="cursor-pointer">
                                            <input type="checkbox" id="status{{$banner->id}}" class="custom-switch-input" name="status" onchange="change_status('banner',{{ $banner->id }})" {{ $banner->status == 1 ? 'checked' : '' }}>
                                            <span class="custom-switch-indicator"></span>
                                        </label>
                                    </td>
                                    @if (Gate::check('banner_edit') || Gate::check('banner_delete'))
                                        <td>
                                            @can('banner_edit')
                                            <a class="text-success" href="{{url('banner/'.$banner->id.'/edit')}}">
                                                <i class="far fa-edit"></i>
                                            </a>
                                            @endcan
                                            @can('banner_delete')
                                                <a class="text-danger" href="javascript:void(0);" onclick="deleteData('banner',{{ $banner->id }})">
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
    </div>
</section>

@endsection
