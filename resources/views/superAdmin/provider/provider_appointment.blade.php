@extends('layout.mainlayout_admin',['activePage' => 'provider'])

@section('title',__('Provider Appointments'))
@section('content')
<section class="section">
    @include('layout.breadcrumb',['title' => __('Provider Appointments'), 'url' => url('provider'), 'urlTitle' => __('Providers')])
    <div class="card">
        <div class="card-body">
            <p class="text-muted">{{ __('View appointments from the provider dashboard.') }}</p>
            <a href="{{ url('provider/'.$provider->id.'/'.Str::slug($provider->name).'/dashboard') }}" class="btn btn-primary">{{ __('Back to Dashboard') }}</a>
        </div>
    </div>
</section>
@endsection
