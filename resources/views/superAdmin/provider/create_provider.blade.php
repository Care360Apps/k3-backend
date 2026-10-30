@extends('layout.mainlayout_admin',['activePage' => 'provider'])

@section('title',__('Add Provider'))
@section('content')
<section class="section">
    @include('layout.breadcrumb',['title' => __('Add Provider'), 'url' => url('provider'), 'urlTitle' => __('Providers')])
    @if (session('status')) @include('superAdmin.auth.status',['status' => session('status')]) @endif
    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>{{ __('Please fix the following errors:') }}</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    <form action="{{ url('provider') }}" method="post" enctype="multipart/form-data" class="myform">
        @csrf
        <div class="card">
            <div class="card-header text-primary">{{__('Personal Information')}}</div>
            <div class="card-body">
                <div class="row mt-4">
                    <div class="col-lg-2 col-md-4">
                        <label class="col-form-label">{{__('Provider image')}}</label>
                        <div class="avatar-upload avatar-box avatar-box-left">
                            <div class="avatar-edit">
                                <input type='file' id="image" name="image" accept=".png, .jpg, .jpeg" />
                                <label for="image"></label>
                            </div>
                            <div class="avatar-preview"><div id="imagePreview"></div></div>
                        </div>
                    </div>
                    <div class="col-lg-10 col-md-8">
                        <div class="form-group">
                            <label class="col-form-label">{{__('Name')}}</label>
                            <input type="text" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="col-form-label">{{__('email')}}</label>
                            <input type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="col-form-label">{{__('Provider Type')}}</label>
                            <select name="provider_type_id" class="form-control select2 @error('provider_type_id') is-invalid @enderror" required>
                                @foreach ($providerTypes as $type)
                                    <option value="{{ $type->id }}" {{ old('provider_type_id') == $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                                @endforeach
                            </select>
                            @error('provider_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-lg-6 form-group">
                        <label class="col-form-label">{{__('Phone number')}}</label>
                        <div class="d-flex">
                            <select name="phone_code" class="phone_code_select2">
                                @foreach ($countries as $country)
                                    <option value="+{{$country->phonecode}}" {{ old('phone_code') == '+'.$country->phonecode ? 'selected' : '' }}>+{{ $country->phonecode }}</option>
                                @endforeach
                            </select>
                            <input type="number" min="1" name="phone" class="form-control" value="{{old('phone')}}" required>
                        </div>
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-6 form-group">
                        <label class="col-form-label">{{__('Date of birth')}}</label>
                        <input type="text" class="form-control datePicker" value="{{old('dob')}}" name="dob" required>
                        @error('dob')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-lg-6 form-group">
                        <label class="col-form-label">{{__('Gender')}}</label>
                        <select name="gender" class="form-control">
                            <option value="male" {{ old('gender') == 'male' ? 'selected' : '' }}>{{__('male')}}</option>
                            <option value="female" {{ old('gender') == 'female' ? 'selected' : '' }}>{{__('female')}}</option>
                            <option value="other" {{ old('gender') == 'other' ? 'selected' : '' }}>{{__('other')}}</option>
                        </select>
                    </div>
                </div>
                @php
                    $setting = \App\Models\Setting::select('lat', 'lang')->first();
                @endphp
                <div class="row mt-4">
                    <div class="pac-card col-md-12 mb-3" id="pac-card">
                        <label for="pac-input" class="col-form-label">{{__('Address / Location')}}</label>
                        <div id="pac-container">
                            <input id="pac-input"
                                   type="text"
                                   name="address"
                                   value="{{ old('address') }}"
                                   class="form-control"
                                   placeholder="{{ __('For home visits') }}">
                            <input type="hidden"
                                   name="lat"
                                   id="lat"
                                   value="{{ old('lat', optional($setting)->lat) }}">
                            <input type="hidden"
                                   name="lng"
                                   id="lng"
                                   value="{{ old('lng', optional($setting)->lang) }}">
                        </div>
                    </div>
                    <div class="col-lg-12">
                        <div id="map" class="mapClass"></div>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-lg-12 form-group">
                        <label class="col-form-label">{{__('Professional Bio')}}</label>
                        <textarea name="desc" rows="5" class="form-control @error('desc') is-invalid @enderror" required>{{old('desc')}}</textarea>
                        @error('desc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header text-primary">{{__('Education and Certificate')}}</div>
            <div class="card-body">
                <div class="education-info">
                    <div class="row form-row education-cont">
                        <div class="col-12 col-md-10 col-lg-11">
                            <div class="row form-row">
                                <div class="col-12 col-md-4"><div class="form-group"><label>{{__('Degree')}}</label><input type="text" required name="degree[]" class="form-control"></div></div>
                                <div class="col-12 col-md-4"><div class="form-group"><label>{{__('College/Institute')}}</label><input type="text" required name="college[]" class="form-control"></div></div>
                                <div class="col-12 col-md-4"><div class="form-group"><label>{{__('Year')}}</label><input type="text" maxlength="4" required name="year[]" class="form-control"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="add-more"><a href="javascript:void(0);" class="add-education"><i class="fa fa-plus-circle"></i>{{__('Add More')}}</a></div>
                <div class="row mt-4">
                    <div class="col-lg-12">
                        <div class="awards-info">
                            <div class="row form-row awards-cont">
                                <div class="col-12 col-md-5"><div class="form-group"><label>{{__('certificate')}}</label><input type="text" required name="certificate[]" class="form-control"></div></div>
                                <div class="col-12 col-md-5"><div class="form-group"><label>{{__('Year')}}</label><input type="text" required name="certificate_year[]" maxlength="4" class="form-control"></div></div>
                            </div>
                        </div>
                        <div class="add-more"><a href="javascript:void(0);" class="add-award"><i class="fa fa-plus-circle"></i> {{__('Add More')}}</a></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header text-primary">{{__('Other Information')}}</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-4 form-group">
                        <label class="col-form-label">{{__('Experience (years)')}}</label>
                        <input type="number" min="1" name="experience" value="{{ old('experience') }}" class="form-control" required>
                    </div>
                    <div class="col-lg-4 form-group">
                        <label class="col-form-label">{{__('Appointment fees')}}</label>
                        <input type="number" min="1" name="appointment_fees" value="{{ old('appointment_fees') }}" class="form-control" required>
                    </div>
                    <div class="col-lg-4 form-group">
                        <label class="col-form-label">{{__('Hourly rate')}}</label>
                        <input type="number" min="0" step="0.01" name="hourly_rate" value="{{ old('hourly_rate') }}" class="form-control" placeholder="{{__('Optional')}}">
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-lg-6 form-group">
                        <label class="col-form-label">{{__('Based On')}}</label>
                        <select name="based_on" class="form-control">
                            <option value="subscription" {{ old('based_on', 'subscription') == 'subscription' ? 'selected' : '' }}>{{__('subscription')}}</option>
                            <option value="commission" {{ old('based_on') == 'commission' ? 'selected' : '' }}>{{__('commission')}}</option>
                        </select>
                    </div>
                    <div class="col-lg-6 form-group base_on_com hide">
                        <label class="col-form-label">{{__('Commission Amount (%)')}}</label>
                        <input type="number" min="1" step="any" name="commission_amount" value="{{ old('commission_amount') }}" class="form-control">
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-lg-6 form-group">
                        <label class="col-form-label">{{__('Password')}}</label>
                        <input type="text" name="password" class="form-control" placeholder="{{__('Leave empty to auto-generate')}}">
                    </div>
                </div>
                <div class="text-right p-2">
                    <button type="submit" class="btn btn-primary">{{__('Submit')}}</button>
                </div>
            </div>
        </div>
    </form>
</section>
@endsection

@section('js')
<script src="{{ url('assets_admin/js/hospital_map.js') }}"></script>
<script src="https://maps.googleapis.com/maps/api/js?key={{App\Models\Setting::first()->map_key}}&callback=initAutocomplete&libraries=places&v=weekly" async></script>
@endsection
