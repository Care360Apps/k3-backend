@extends('layout.mainlayout_admin',['activePage' => 'login'])

@section('title', __('Provider Register'))

@section('content')
<section class="section">
  <div class="d-flex flex-wrap align-items-stretch">
    <div class="col-lg-4 col-md-6 col-12 order-lg-1 min-vh-100 order-2 bg-white">
      <div class="p-4 m-3">
        @php
          $setting = App\Models\Setting::first();
        @endphp
        @if(isset($setting->logo))
          <img src="{{ $setting->logo }}" alt="logo" width="180" class="mb-5 mt-2">
        @else
          <img src="{{ url('/images/upload_empty/logo_black.png') }}" class="h-6 mr-3 sm:h-9" alt="Doctro Logo" />
        @endif

        @if ($errors->any())
          @foreach ($errors->all() as $item)
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              {{ $item }}
            </div>
          @endforeach
        @endif

        <form action="{{ url('provider_register') }}" method="post" enctype="multipart/form-data">
          @csrf
          <div class="form-group">
            <label for="name">{{ __('Name') }}</label>
            <input class="form-control @error('name') is-invalid @enderror"
                   name="name" value="{{ old('name') }}" type="text">
            @error('name')
            <div class="invalid-feedback">
              {{ $message }}
            </div>
            @enderror
          </div>

          <div class="form-group">
            <label for="email">{{ __('Email') }}</label>
            <input class="form-control @error('email') is-invalid @enderror"
                   name="email" value="{{ old('email') }}" type="email">
            @error('email')
            <div class="invalid-feedback">
              {{ $message }}
            </div>
            @enderror
          </div>

          <div class="form-group">
            <label for="phone">{{ __('Phone') }}</label>
            <div class="d-flex @error('phone') is-invalid @enderror">
              <select name="phone_code" class="phone_code_select2">
                @foreach ($countries as $country)
                  <option value="+{{$country->phonecode}}"
                    {{ (env('DEFAULT_DIALING_CODE') == $country->phonecode) ? 'selected' : '' }}>
                    +{{ $country->phonecode }}
                  </option>
                @endforeach
              </select>
              <input type="number" value="{{ old('phone') }}" name="phone" class="form-control">
            </div>
            @error('phone')
            <div class="invalid-feedback">
              {{ $message }}
            </div>
            @enderror
          </div>

          @php
            $setting = App\Models\Setting::select('lat', 'lang')->first();
            $providerTypes = \App\Models\ProviderType::where('status', 1)->get();
          @endphp

          <div class="form-group">
            <label class="col-form-label">{{ __('Address / Location (for map)') }}</label>
            <div id="pac-container">
              <input id="pac-input" type="text" name="address"
                     value="{{ old('address') }}"
                     class="form-control @error('address') is-invalid @enderror"
                     placeholder="{{ __('For home visits') }}">
              <input type="hidden" name="lat" id="lat" value="{{ old('lat', optional($setting)->lat) }}">
              <input type="hidden" name="lng" id="lng" value="{{ old('lng', optional($setting)->lang) }}">
            </div>
            @error('address')
            <div class="invalid-feedback">
              {{ $message }}
            </div>
            @enderror
          </div>

          <div class="form-group mb-3">
            <div id="map" class="mapClass"></div>
          </div>

          <div class="form-group">
            <label for="provider_type_id">{{ __('Provider type') }}</label>
            <select name="provider_type_id" class="form-control @error('provider_type_id') is-invalid @enderror">
              <option value="">{{ __('Select provider type') }}</option>
              @foreach($providerTypes as $type)
                <option value="{{ $type->id }}" {{ old('provider_type_id') == $type->id ? 'selected' : '' }}>
                  {{ $type->name }}
                </option>
              @endforeach
            </select>
            @error('provider_type_id')
            <div class="invalid-feedback">
              {{ $message }}
            </div>
            @enderror
          </div>

          <div class="form-group">
            <label for="password">{{ __('Password') }}</label>
            <input class="form-control @error('password') is-invalid @enderror"
                   name="password" type="password">
            @error('password')
            <div class="invalid-feedback">
              {{ $message }}
            </div>
            @enderror
          </div>

          @include('superAdmin.doctor.document_upload_field')

          <div class="form-group text-right">
            <span class="float-left">
              {{ __('Already have an account?') }}
              <a href="{{ url('provider_login') }}">{{ __('Login') }}</a>
            </span>
            <button class="btn btn-primary btn-lg btn-icon icon-right" type="submit">
              {{ __('Sign Up') }}
            </button>
          </div>
        </form>
      </div>
    </div>
    <div class="col-lg-8 col-12 order-lg-2 order-1 min-vh-100 background-walk-y position-relative overlay-gradient-bottom"
         data-background="{{ url('assets/img/login.png') }}">
      <div class="absolute-bottom-left index-2">
        <div class="text-light p-5 pb-2">
          <div class="mb-5 pb-3">
            <h1 class="mb-2 display-4 font-weight-bold">{{ __('Welcome') }}</h1>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
@endsection

@section('js')
<script src="{{ url('assets_admin/js/hospital_map.js') }}"></script>
<script src="https://maps.googleapis.com/maps/api/js?key={{App\Models\Setting::first()->map_key}}&callback=initAutocomplete&libraries=places&v=weekly" async></script>
@endsection

