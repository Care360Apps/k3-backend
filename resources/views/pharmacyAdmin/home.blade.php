@extends('layout.mainlayout_admin',['activePage' => 'home'])

@section('title', __('pharmacy Dashboard'))

@section('content')

    <section class="section">
        @include('layout.breadcrumb',['title' => __('Pharmacy Dashboard')])

        <div class="card mb-4 border-0" style="background:#f1f8f5;border-radius:16px;">
            <div class="card-body d-md-flex justify-content-between align-items-center">
                <div class="mb-3 mb-md-0">
                    <h4 class="mb-2" style="color:#174b42;">{{ __('Your pharmacy at a glance') }}</h4>
                    <p class="mb-0 text-muted">{{ __('Review your inventory, import medicines in bulk, and understand earnings before settlement.') }}</p>
                </div>
                <div class="d-flex flex-wrap" style="gap:10px;">
                    <a class="btn btn-primary" href="{{ url('medicines') }}">{{ __('Manage medicines') }}</a>
                    <a class="btn btn-outline-primary" href="{{ url('medicines/bulk_template') }}">{{ __('Download CSV template') }}</a>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-primary"><i class="far fa-money-bill-alt"></i></div>
                    <div class="card-wrap">
                        <div class="card-header"><h4>{{ __('Pharmacy Earnings Today') }}</h4></div>
                        <div class="card-body"><h3>{{ $currency }}{{ number_format($today_sells, 2) }}</h3></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-warning"><i class="fas fa-tablets"></i></div>
                    <div class="card-wrap">
                        <div class="card-header"><h4>{{ __('Listed Medicines') }}</h4></div>
                        <div class="card-body"><h3>{{ number_format($total_medicines) }}</h3></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-info"><i class="fas fa-exclamation-circle"></i></div>
                    <div class="card-wrap">
                        <div class="card-header"><h4>{{ __('Low Stock (1–10)') }}</h4></div>
                        <div class="card-body"><h3>{{ number_format($lowStock) }}</h3></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-danger"><i class="fas fa-box-open"></i></div>
                    <div class="card-wrap">
                        <div class="card-header"><h4>{{ __('Out of Stock') }}</h4></div>
                        <div class="card-body"><h3>{{ number_format($outOfStock) }}</h3></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="mb-2">{{ __('Your Care360 marketplace fee') }}: {{ number_format($commissionRate, 2) }}%</h5>
                <p class="text-muted mb-2">{{ __('The percentage applies to the selling price of medicines, not to delivery charges. Your pharmacy receives the remaining share before separately disclosed payment-processing costs.') }}</p>
                <a href="{{ url('pharmacyCommission') }}">{{ __('View earnings and settlement history') }}</a>
                <span class="d-block mt-2 small text-muted">{{ __('To request a commission adjustment, contact a Care360 administrator. Your medicine prices remain yours to set.') }}</span>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12 col-sm-12 col-12">
                <div class="card card-chart">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('Revenue') }}</h4>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueChart"></canvas>
                        <input type="hidden" name="years" value="{{ $revenueCharts['label'] }}">
                        <input type="hidden" name="data" value="{{ $revenueCharts['data'] }}">
                    </div>
                </div>
            </div>
        </div>
    </section>

@endsection

@section('js')
    <script src="{{ url('assets_admin/js/chart.min.js') }}"></script>
    <script src="{{ url('assets_admin/js/chart.js') }}"></script>
@endsection
