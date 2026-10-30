@extends('layout.mainlayout_admin',['activePage' => 'course'])

@section('title',__('Edit Course'))
@section('content')
<section class="section">
    @include('layout.breadcrumb',[
        'title' => __('Edit Course'),
        'url' => url('course'),
        'urlTitle' => __('Courses'),
    ])
    @if (session('status'))
        @include('superAdmin.auth.status',[
            'status' => session('status')])
    @endif
    <div class="section_body">
        <div class="card">
            <form action="{{ url('course/'.$course->id) }}" method="post" enctype="multipart/form-data" class="myform">
                @csrf
                @method('PUT')
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-2 col-md-4">
                            <label class="col-form-label">{{__('Course Image')}}</label>
                            <div class="avatar-upload avatar-box avatar-box-left">
                                <div class="avatar-edit">
                                    <input type='file' name="image" id="image" accept=".png, .jpg, .jpeg" />
                                    <label for="image"></label>
                                </div>
                                <div class="avatar-preview">
                                    <div id="imagePreview" style="background-image: url({{ $course->fullImage }});">
                                    </div>
                                </div>
                            </div>
                            @error('image')
                                <div class="custom_error">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>
                        <div class="col-lg-10 col-md-8">
                            <div class="form-group">
                                <label class="col-form-label">{{__('Course Title')}}</label>
                                <input type="text" value="{{ $course->title }}" name="title" class="form-control @error('title') is-invalid @enderror">
                                @error('title')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="col-form-label">{{__('Course Duration')}}</label>
                                <input type="text" value="{{ $course->duration }}" name="duration" class="form-control @error('duration') is-invalid @enderror">
                                @error('duration')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="col-form-label">{{__('Amount')}}</label>
                                <input type="number" step="0.01" value="{{ $course->amount }}" name="amount" class="form-control @error('amount') is-invalid @enderror">
                                @error('amount')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="col-form-label">{{__('Link')}}</label>
                                <input type="text" value="{{ $course->link }}" name="link" class="form-control @error('link') is-invalid @enderror">
                                @error('link')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>
                    </div>
                    <div class="form-group mt-4">
                        <label class="col-form-label">{{__('Course Summary')}}</label>
                        <textarea name="summary" rows="3" class="form-control @error('summary') is-invalid @enderror">{{ $course->summary }}</textarea>
                        @error('summary')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label class="col-form-label">{{__('Course Details')}}</label>
                        <textarea name="details" class="summernote form-control @error('details') is-invalid @enderror">{{ $course->details }}</textarea>
                        @error('details')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                    <div class="text-right">
                        <button type="submit" class="btn btn-primary">{{__('Submit')}}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>
@endsection
