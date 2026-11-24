@extends('layout.mainlayout_admin',['activePage' => 'course'])

@section('title',__('All Courses'))
@section('content')
<section class="section">
    @include('layout.breadcrumb',[
        'title' => __('Courses'),
    ])
    @if (session('status'))
        @include('superAdmin.auth.status',[
            'status' => session('status')])
    @endif
    <div class="section_body">
        <div class="card">
            <div class="card-header w-100 text-right d-flex justify-content-between">
                @include('superAdmin.auth.exportButtons')
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
                                <th>Provider / publication</th><th>{{__('Duration')}}</th>
                                <th>{{__('Amount')}}</th>
                                <th>{{__('Link')}}</th>
                                <th>{{__('Summary')}}</th>
                                @if (Gate::check('course_edit') || Gate::check('course_delete'))
                                    <th>{{__('Actions')}}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($courses as $course)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        <a href="{{ $course->fullImage }}" data-fancybox="gallery2">
                                            <img src="{{ $course->fullImage }}" width="50" height="50" class="rounded" alt="">
                                        </a>
                                    </td>
                                    <td>{{ $course->title }}</td>
                                    <td>{{ $course->provider === 'speakout' ? 'SpeakOut' : 'CNU' }} / {{ $course->is_published ? 'Published' : 'Draft' }}</td><td>{{ $course->duration }}</td>
                                    <td>{{ $course->provider === 'speakout' ? '100% free' : ($course->amount > 0 ? 'NGN '.number_format($course->amount, 2) : 'Contact for tuition') }}</td>
                                    <td>
                                        <a href="{{ $course->link }}" target="_blank" rel="noopener noreferrer">{{ __('Open') }}</a>
                                    </td>
                                    <td>{{ \Illuminate\Support\Str::limit(strip_tags($course->summary), 80) }}</td>
                                    @if (Gate::check('course_edit') || Gate::check('course_delete'))
                                        <td>
                                            @can('course_edit')
                                                <a class="text-success" href="{{ url('course/'.$course->id.'/edit') }}">
                                                    <i class="far fa-edit"></i>
                                                </a>
                                            @endcan
                                            @can('course_delete')
                                                <a class="text-danger" href="javascript:void(0);" onclick="deleteData('course',{{ $course->id }})">
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
    </div>
</section>
@endsection
