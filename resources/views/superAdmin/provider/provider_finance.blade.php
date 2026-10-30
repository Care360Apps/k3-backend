@extends('layout.mainlayout_admin',['activePage' => 'provider'])

@section('title',__('Provider Finance'))
@section('content')
<section class="section">
    @include('layout.breadcrumb',['title' => __('Provider Finance Details'), 'url' => url('provider'), 'urlTitle' => __('Providers')])
    <div class="section_body">
        <div class="card profile-widget mt-5">
            <div class="profile-widget-header">
                <a href="{{ $provider->fullImage }}" data-fancybox="gallery2">
                    <img alt="image" src="{{ $provider->fullImage }}" class="rounded-circle profile-widget-picture">
                </a>
                <div class="btn-group mb-2 dropleft float-right p-3">
                    <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-toggle="dropdown">
                    {{ __('More Details') }}
                    </button>
                    <div class="dropdown-menu">
                      <a class="dropdown-item" href="{{ url('provider/'.$provider->id.'/'.Str::slug($provider->name).'/dashboard') }}">{{ __('Dashboard') }}</a>
                      <a class="dropdown-item" href="{{ url('provider/'.$provider->id.'/'.Str::slug($provider->name).'/schedule') }}">{{ __('Schedule Timing') }}</a>
                    </div>
                </div>
            </div>
            <div class="profile-widget-description">
                <div class="profile-widget-name">{{ $provider->name }}
                    @if (isset($provider->providerType))
                    <div class="text-muted d-inline font-weight-normal"><div class="slash"></div>{{ $provider->providerType->name }}</div>
                    @endif
                </div>
                {{ $provider->desc }}
            </div>
        </div>

        @if (isset($provider->based_on) && $provider->based_on == 'subscription' && isset($subscriptions))
            <div class="card">
                <div class="card-header"><h5>{{__('Based On ')}}{{ $provider->based_on }}</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="datatable table table-hover table-center mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{__('subscription name')}}</th>
                                    <th>{{__('Provider name')}}</th>
                                    <th>{{__('payment')}}</th>
                                    <th>{{__('payment type')}}</th>
                                    <th>{{__('payment status')}}</th>
                                    <th>{{__('subscription date')}}</th>
                                    <th>{{__('Status')}}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($subscriptions as $subscription)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>{{ $subscription->subscription->name ?? '-' }}</td>
                                        <td>{{ $subscription->doctor->name ?? '-' }}</td>
                                        <td>{{ $currency }}{{ $subscription->amount ?? 0 }}</td>
                                        <td>{{ $subscription->payment_type ?? '-' }}</td>
                                        <td>
                                            @if (($subscription->payment_status ?? 0) == 0)
                                                <span class="btn btn-sm bg-danger-light">{{__('unpaid')}}</span>
                                            @else
                                                <span class="btn btn-sm bg-success-light">{{__('paid')}}</span>
                                            @endif
                                        </td>
                                        <td>{{ ($subscription->start_date ?? '') . ' to ' . ($subscription->end_date ?? '') }}</td>
                                        <td>
                                            @if (($subscription->status ?? 0) == 0)
                                                <span class="badge badge-pill bg-danger">{{__('expired')}}</span>
                                            @else
                                                <span class="badge badge-pill bg-success">{{__('currently available')}}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        @if (isset($provider->based_on) && $provider->based_on == 'commission' && isset($settels))
            <div class="card">
                <div class="card-header"><strong>{{__('Last 7 days earning')}}</strong></div>
                <div class="card-body">
                    @if(isset($appointments))
                    <div class="table-responsive">
                        <table class="table datatable table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{__('Date')}}</th>
                                    <th>{{__('Order Amount')}}</th>
                                    <th>{{__('Admin Commission')}}</th>
                                    <th>{{__('Provider earning')}}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($appointments as $order)
                                <tr>
                                    <td>{{$loop->iteration}}</td>
                                    <td>{{ $order['date'] ?? '-' }}</td>
                                    <td>{{ $currency }}{{ $order['amount'] ?? 0 }}</td>
                                    <td>{{ $currency }}{{ $order['admin_commission'] ?? 0 }}</td>
                                    <td>{{ $currency }}{{ $order['doctor_commission'] ?? 0 }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h4>{{__('Settlements')}}</h4></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{__('duration')}}</th>
                                    <th>{{__('Order count')}}</th>
                                    <th>{{__('Admin Earning')}}</th>
                                    <th>{{__('Provider earning')}}</th>
                                    <th>{{__('Settles amount')}}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($settels as $settel)
                                    <tr>
                                        <td>{{$loop->iteration}}</td>
                                        <td>{{ $settel['duration'] ?? '-' }}</td>
                                        <td>{{ $settel['d_total_task'] ?? 0 }}</td>
                                        <td>{{ $currency }}{{ $settel['admin_earning'] ?? 0 }}</td>
                                        <td>{{ $currency }}{{ $settel['doctor_earning'] ?? 0 }}</td>
                                        <td>
                                            @if(($settel['d_balance'] ?? 0) > 0)
                                                <span class="badge badge-success">{{ $currency }}{{ abs($settel['d_balance']) }}</span>
                                            @else
                                                <span class="badge badge-danger">{{ $currency }}{{ abs($settel['d_balance'] ?? 0) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
</section>
@endsection
