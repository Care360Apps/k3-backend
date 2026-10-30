@extends('layout.mainlayout_admin',['activePage' => 'lab'])

@section('css')
<link rel="stylesheet" href="{{url('assets/plugins/dropzone/dropzone.min.css')}}">
@endsection

@section('title',__('Laboratory Gallery'))
@section('content')

<section class="section">
    @include('layout.breadcrumb',[
        'title' => __('Laboratory Gallery'),
        'url' => url('laboratory'),
        'urlTitle' => __('Laboratory'),
    ])

        @if (session('status'))
        @include('superAdmin.auth.status',[
            'status' => session('status')])
        @endif

        <div class="card">
            <div class="card-body">
                <form method="post" action="{{ url('labGallery') }}" enctype="multipart/form-data" id="mydropzone" class="dropzone myform">
                    @csrf
                    <input type="hidden" name="lab_id" value="{{ $lab->id }}">
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <div class="upload-wrap">
                    @foreach ($galleries as $gallery)
                        <div class="upload-images">
                            <a href="{{ $gallery->fullImage }}" data-fancybox="gallery2">
                                <img src="{{ url($gallery->fullImage) }}" alt="Upload Image">
                            </a>
                            <a href="javascript:void(0);" onclick="deleteData('labGallery',{{ $gallery->id }})" class="btn btn-icon btn-danger btn-sm"><i class="far fa-trash-alt"></i></a>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
</section>

@endsection

@section('js')
    <script src="{{url('assets/plugins/dropzone/dropzone.min.js')}}"></script>
    <script>
        "use strict";
        var totalSteps;
        var dropzone = new Dropzone("#mydropzone", {
        });
    </script>
@endsection
