@extends('layout.mainlayout_admin',['activePage' => 'lab'])

@section('title',__('Edit Laboratory'))

@section('content')
<section class="section">

    @if (!auth()->user()->hasRole('laboratory') && Gate::check('lab_edit'))
        @include('layout.breadcrumb',[
            'title' => __('Edit Laboratory'),
            'url' => url('laboratory'),
            'urlTitle' => __('Laboratory'),
        ])
    @else
        @include('layout.breadcrumb',[
            'title' => __('Laboratory Profile'),
        ])
    @endif

    @if (session('status'))
        @include('superAdmin.auth.status',['status' => session('status')])
    @endif

    <div class="section_body">
        <div class="card">
            <form action="{{ url('laboratory/'.$lab->id) }}" method="post" enctype="multipart/form-data" class="myform">
                @csrf
                @method('PUT')
                <div class="card-body">
                        <div class="row">
                            <div class="col-lg-12 col-md-12">
                                <label for="lab_image" class="col-form-label"> {{__('Laboratory image')}}</label>
                                <div class="avatar-upload avatar-box avatar-box-left">
                                    <div class="avatar-edit">
                                        <input type='file' id="image" name="image" accept=".png, .jpg, .jpeg" />
                                        <label for="image"></label>
                                    </div>
                                    <div class="avatar-preview">
                                        <div id="imagePreview" style="background-image: url({{ $lab->fullImage }});">
                                        </div>
                                    </div>
                                </div>
                                @error('image')
                                    <div class="custom_error">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-form-label">{{__('Laboratory Name')}}</label>
                            <input type="text" required value="{{ old('name',$lab->name) }}" name="name" class="form-control @error('name') is-invalid @enderror">
                            @error('name')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                            @enderror
                        </div>
                        <div class="form-group">
                            <label class="col-form-label">{{__('Description')}}</label>
                            <textarea name="description" class="form-control @error('description') is-invalid @enderror" rows="4">{{ old('description',$lab->description) }}</textarea>
                            @error('description')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                            @enderror
                        </div>
                        <div class="form-group">
                            <label class="col-form-label">{{__('Laboratory images')}}</label>
                            <input type="file" name="gallery_images[]" multiple accept=".png, .jpg, .jpeg" class="form-control @error('gallery_images.*') is-invalid @enderror">
                            @error('gallery_images.*')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                            @enderror
                            @if(isset($galleries))
                                <div class="upload-wrap mt-3">
                                    @foreach ($galleries as $gallery)
                                        <div class="upload-images">
                                            <img src="{{ url($gallery->fullImage) }}" alt="Upload Image">
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            <div class="upload-wrap mt-3" id="lab-gallery-preview"></div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-lg-6 form-group">
                                <label for="phone_number" class="col-form-label"> {{__('Phone number')}}</label>
                                <input type="text" value="{{ $lab->user['phone_code'] }}{{ $lab->user['phone'] }}" readonly disabled class="form-control">
                                @error('phone')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>
                            <div class="col-lg-6 form-group">
                                <label for="email" class="col-form-label"> {{__('Email')}}</label>
                                <input type="email" value="{{ old('email',$lab->user['email']) }}" disabled name="email" class="form-control @error('email') is-invalid @enderror">
                                @error('email')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                                @error('email')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-lg-6 form-group">
                                <label class="col-form-label">{{__('Start Time')}}</label>
                                <input class="form-control timepicker @error('start_time') is-invalid @enderror" name="start_time" value="{{ old('start_time',$lab->start_time) }}" type="time">
                                @error('start_time')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                            <div class="col-lg-6 form-group">
                                <label class="col-form-label">{{__('End Time')}}</label>
                                <input class="form-control timepicker @error('end_time') is-invalid @enderror" name="end_time" value="{{ old('end_time',$lab->end_time) }}" type="time">
                                @error('end_time')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-form-label">{{__('Commission')}}({{ __('In Percantage') }})</label>
                            <input class="form-control @error('commission') is-invalid @enderror" readonly value="{{ $lab->commission }}" type="number" min="1">
                            @error('commission')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>

                        <div class="row mt-4">
                            <div class="pac-card col-md-12 mb-3" id="pac-card">
                                <label for="pac-input col-form-label">{{__('Location based on latitude/longitude')}}</label>
                                <div id="pac-container">
                                    <input id="pac-input" type="text" name="address" class="form-control @error('address') is-invalid @enderror" value="{{ $lab->address }}"/>
                                    @error('address')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                    <input type="hidden" name="lat" value="{{$lab->lat}}" id="lat">
                                    <input type="hidden" name="lng" value="{{$lab->lng}}" id="lng">
                                </div>
                            </div>
                            <div class="col-lg-12">
                                <div id="map" class="mapClass"></div>
                            </div>
                        </div>
                        <div class="text-right mt-4">
                            <button type="submit" class="btn btn-primary">{{__('Submit')}}</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>

@endsection

@section('js')
    <script src="https://maps.googleapis.com/maps/api/js?key={{App\Models\Setting::first()->map_key}}&callback=initAutocomplete&libraries=places&v=weekly" async></script>
    <script src="{{ url('assets_admin/js/hospital_map.js') }}"></script>
    <script>
        "use strict";
        function renderLabGalleryPreview(input, containerId) {
            const container = document.getElementById(containerId);
            if (!container) {
                return;
            }
            container.innerHTML = '';
            if (!input.files || !input.files.length) {
                return;
            }
            Array.from(input.files).forEach((file) => {
                const reader = new FileReader();
                reader.onload = function (event) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'upload-images';
                    const img = document.createElement('img');
                    img.src = event.target.result;
                    img.alt = 'Upload Image';
                    wrapper.appendChild(img);
                    container.appendChild(wrapper);
                };
                reader.readAsDataURL(file);
            });
        }
        document.addEventListener('DOMContentLoaded', function () {
            const input = document.querySelector('input[name="gallery_images[]"]');
            if (input) {
                input.addEventListener('change', function () {
                    renderLabGalleryPreview(input, 'lab-gallery-preview');
                });
            }
        });
    </script>
@endsection
