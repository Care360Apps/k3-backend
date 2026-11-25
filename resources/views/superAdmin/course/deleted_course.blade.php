@extends('layout.mainlayout_admin',['activePage' => 'course_deleted'])

@section('title',__('Deleted Courses'))
@section('content')
<section class="section">
    @include('layout.breadcrumb',[
        'title' => __('Deleted Courses'),
        'url' => url('course'),
        'urlTitle' => __('Courses'),
    ])
    @if (session('status'))
        @include('superAdmin.auth.status',[
            'status' => session('status')])
    @endif
    <div class="section_body">
        <div class="card">
            <div class="card-header w-100 text-right d-flex justify-content-between">
                <div></div>
                @can('course_add')
                    <a href="{{ url('course/create') }}">{{ __('Add New') }}</a>
                @endcan
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable table-center mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{__('Image')}}</th>
                                <th>{{__('Title')}}</th>
                                <th>{{__('Duration')}}</th>
                                <th>{{__('Amount')}}</th>
                                <th>{{__('Link')}}</th>
                                @if (Gate::check('course_delete'))
                                    <th>{{__('Actions')}}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($courses as $course)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        <a href="{{ ($course->image ? route('course.image.preview', ['id' => $course->id]) : $course->fullImage) }}" data-fancybox="gallery2">
                                            <img src="{{ ($course->image ? route('course.image.preview', ['id' => $course->id]) : $course->fullImage) }}" width="50" height="50" class="rounded" alt="">
                                        </a>
                                    </td>
                                    <td>{{ $course->title }}</td>
                                    <td>{{ $course->duration }}</td>
                                    <td>{{ number_format($course->amount, 2) }}</td>
                                    <td>
                                        <a href="{{ $course->link }}" target="_blank">{{ __('Open') }}</a>
                                    </td>
                                    @if (Gate::check('course_delete'))
                                        <td>
                                            <a class="text-success" href="{{ url('course/restore/'.$course->id) }}">
                                                <i class="fas fa-undo"></i>
                                            </a>
                                            <a class="text-danger" href="javascript:void(0);" onclick="deleteData('course/force-delete',{{ $course->id }})">
                                                <i class="far fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
