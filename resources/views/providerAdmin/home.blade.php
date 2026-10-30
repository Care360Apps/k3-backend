@extends('layout.mainlayout_admin',['activePage' => 'provider_home'])

@section('title', __('Provider Dashboard'))

@section('content')
<section class="section">
  <div class="section-body">
    <div class="row">
      <div class="col-12">
        <div class="card">
          <div class="card-header">
            <h4>{{ __('Welcome,') }} {{ optional($provider)->name ?? auth()->user()->name }}</h4>
          </div>
          <div class="card-body">
            <p class="mb-0">
              {{ __('This is your provider dashboard. From here you will be able to manage your profile, appointments and finances.') }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
@endsection

