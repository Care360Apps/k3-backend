@extends('layout.mainlayout_admin', ['activePage' => 'withdrawal_requests'])

@section('title', __('Withdrawal Requests'))
@section('content')
<section class="section">
    @include('layout.breadcrumb', [
        'title' => __('Withdrawal Requests'),
        'url' => url('home'),
        'urlTitle' => __('Dashboard'),
    ])

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 rounded-xl" role="alert">
            {{ session('status') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 rounded-xl" role="alert">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    @endif

    <div class="section_body">
        {{-- Stats cards --}}
        <div class="row mb-4">
            <div class="col-12 col-sm-6 col-lg-3 mb-3">
                <div class="card border-0 shadow-sm rounded-xl overflow-hidden h-100 withdrawal-stat-card withdrawal-stat-pending">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle withdrawal-stat-icon p-3 mr-3">
                            <i class="fas fa-clock fa-lg text-white"></i>
                        </div>
                        <div>
                            <div class="text-white-50 text-uppercase small font-weight-bold">{{ __('Pending') }}</div>
                            <div class="h4 mb-0 text-white font-weight-bold">{{ $counts['pending'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3 mb-3">
                <div class="card border-0 shadow-sm rounded-xl overflow-hidden h-100 withdrawal-stat-card withdrawal-stat-approved">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle withdrawal-stat-icon p-3 mr-3">
                            <i class="fas fa-check-circle fa-lg text-white"></i>
                        </div>
                        <div>
                            <div class="text-white-50 text-uppercase small font-weight-bold">{{ __('Approved') }}</div>
                            <div class="h4 mb-0 text-white font-weight-bold">{{ $counts['approved'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3 mb-3">
                <div class="card border-0 shadow-sm rounded-xl overflow-hidden h-100 withdrawal-stat-card withdrawal-stat-rejected">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle withdrawal-stat-icon p-3 mr-3">
                            <i class="fas fa-times-circle fa-lg text-white"></i>
                        </div>
                        <div>
                            <div class="text-white-50 text-uppercase small font-weight-bold">{{ __('Rejected') }}</div>
                            <div class="h4 mb-0 text-white font-weight-bold">{{ $counts['rejected'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3 mb-3">
                <div class="card border-0 shadow-sm rounded-xl overflow-hidden h-100 withdrawal-stat-card withdrawal-stat-paid">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle withdrawal-stat-icon p-3 mr-3">
                            <i class="fas fa-money-check-alt fa-lg text-white"></i>
                        </div>
                        <div>
                            <div class="text-white-50 text-uppercase small font-weight-bold">{{ __('Paid') }}</div>
                            <div class="h4 mb-0 text-white font-weight-bold">{{ $counts['paid'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Filters --}}
        <div class="card border-0 shadow-sm rounded-xl mb-4">
            <div class="card-body">
                <form method="get" action="{{ route('withdrawal_requests.index') }}" class="form-inline flex-wrap gap-2">
                    <label class="mr-2 mb-2 mb-md-0">{{ __('Status') }}:</label>
                    <select name="status" class="form-control rounded-pill mr-2 mb-2 mb-md-0" style="min-width: 140px;">
                        <option value="">{{ __('All') }}</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                        <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>{{ __('Approved') }}</option>
                        <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>{{ __('Rejected') }}</option>
                        <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>{{ __('Paid') }}</option>
                    </select>
                    <label class="mr-2 mb-2 mb-md-0">{{ __('Type') }}:</label>
                    <select name="holder_type" class="form-control rounded-pill mr-2 mb-2 mb-md-0" style="min-width: 140px;">
                        <option value="">{{ __('All') }}</option>
                        <option value="doctor" {{ request('holder_type') === 'doctor' ? 'selected' : '' }}>{{ __('Doctor') }}</option>
                        <option value="provider" {{ request('holder_type') === 'provider' ? 'selected' : '' }}>{{ __('Provider') }}</option>
                        <option value="pharmacy" {{ request('holder_type') === 'pharmacy' ? 'selected' : '' }}>{{ __('Pharmacy') }}</option>
                        <option value="lab" {{ request('holder_type') === 'lab' ? 'selected' : '' }}>{{ __('Lab') }}</option>
                    </select>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 mb-2 mb-md-0"><i class="fas fa-filter mr-1"></i> {{ __('Filter') }}</button>
                </form>
            </div>
        </div>

        {{-- Table --}}
        <div class="card border-0 shadow-sm rounded-xl overflow-hidden">
            <div class="card-header bg-transparent border-0 py-4">
                <h5 class="mb-0 font-weight-bold text-dark">{{ __('Withdrawal Requests') }}</h5>
            </div>
            <div class="card-body p-0">
                @if ($requests->isEmpty())
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-3x mb-3 opacity-50"></i>
                        <p class="mb-0">{{ __('No withdrawal requests found.') }}</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover table-center mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th class="border-0 py-3">#</th>
                                    <th class="border-0 py-3">{{ __('Type') }}</th>
                                    <th class="border-0 py-3">{{ __('Name') }}</th>
                                    <th class="border-0 py-3">{{ __('Amount') }}</th>
                                    <th class="border-0 py-3">{{ __('Payout Account') }}</th>
                                    <th class="border-0 py-3">{{ __('Status') }}</th>
                                    <th class="border-0 py-3">{{ __('Requested') }}</th>
                                    <th class="border-0 py-3 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($requests as $wr)
                                    <tr>
                                        <td class="align-middle">{{ $wr->id }}</td>
                                        <td class="align-middle">
                                            <span class="badge badge-pill badge-light text-capitalize px-3 py-2">{{ $wr->holder_type }}</span>
                                        </td>
                                        <td class="align-middle font-weight-medium">{{ $wr->holder_name }}</td>
                                        <td class="align-middle font-weight-bold text-success">{{ $currency }}{{ number_format($wr->amount, 2) }}</td>
                                        <td class="align-middle">
                                            @if ($wr->payout_account_name)
                                                <div class="payout-details-cell">
                                                    <div class="d-flex align-items-center">
                                                        <div class="payout-icon-wrap mr-2">
                                                            <i class="fas fa-university text-primary"></i>
                                                        </div>
                                                        <div>
                                                            <div class="font-weight-semibold text-dark" style="font-size:0.85rem;">{{ $wr->payout_account_name }}</div>
                                                            <div class="text-muted" style="font-size:0.78rem;">{{ $wr->payout_bank_name }} &bull; <span class="font-weight-medium text-dark">{{ $wr->payout_account_number }}</span></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @else
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                        <td class="align-middle">
                                            @if ($wr->status === 'pending')
                                                <span class="badge badge-pill badge-warning px-3 py-2">{{ __('Pending') }}</span>
                                            @elseif ($wr->status === 'approved')
                                                <span class="badge badge-pill badge-info px-3 py-2">{{ __('Approved') }}</span>
                                            @elseif ($wr->status === 'rejected')
                                                <span class="badge badge-pill badge-danger px-3 py-2">{{ __('Rejected') }}</span>
                                            @else
                                                <span class="badge badge-pill badge-success px-3 py-2">{{ __('Paid') }}</span>
                                            @endif
                                        </td>
                                        <td class="align-middle text-muted small">{{ $wr->created_at->format('M d, Y H:i') }}</td>
                                        <td class="align-middle text-right">
                                            {{-- View details button always visible --}}
                                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 mb-1"
                                                data-toggle="modal" data-target="#detailModal"
                                                data-id="{{ $wr->id }}"
                                                data-name="{{ $wr->holder_name }}"
                                                data-type="{{ $wr->holder_type }}"
                                                data-amount="{{ $currency }}{{ number_format($wr->amount, 2) }}"
                                                data-status="{{ $wr->status }}"
                                                data-account-name="{{ $wr->payout_account_name ?? '—' }}"
                                                data-account-number="{{ $wr->payout_account_number ?? '—' }}"
                                                data-bank-name="{{ $wr->payout_bank_name ?? '—' }}"
                                                data-created="{{ $wr->created_at->format('M d, Y H:i') }}"
                                                data-ref="{{ $wr->payment_reference ?? '' }}"
                                                data-notes="{{ $wr->notes ?? '' }}"
                                                data-rejected-reason="{{ $wr->rejected_reason ?? '' }}"
                                                data-paid-at="{{ $wr->paid_at ? $wr->paid_at->format('M d, Y H:i') : '' }}">
                                                <i class="fas fa-eye mr-1"></i> {{ __('View') }}
                                            </button>
                                            @if ($wr->status === 'pending')
                                                <a href="{{ route('withdrawal_requests.approve', $wr->id) }}"
                                                    class="btn btn-sm btn-success rounded-pill px-3 mb-1"
                                                    onclick="return confirm('{{ __('Approve this request?') }}');">
                                                    <i class="fas fa-check mr-1"></i> {{ __('Approve') }}
                                                </a>
                                                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 mb-1"
                                                    data-toggle="modal" data-target="#rejectModal" data-id="{{ $wr->id }}">
                                                    <i class="fas fa-times mr-1"></i> {{ __('Reject') }}
                                                </button>
                                            @elseif ($wr->status === 'approved')
                                                <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 mb-1"
                                                    data-toggle="modal" data-target="#markPaidModal"
                                                    data-id="{{ $wr->id }}"
                                                    data-account-name="{{ $wr->payout_account_name ?? '—' }}"
                                                    data-account-number="{{ $wr->payout_account_number ?? '—' }}"
                                                    data-bank-name="{{ $wr->payout_bank_name ?? '—' }}"
                                                    data-amount="{{ $currency }}{{ number_format($wr->amount, 2) }}"
                                                    data-name="{{ $wr->holder_name }}">
                                                    <i class="fas fa-money-check-alt mr-1"></i> {{ __('Mark as paid') }}
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @if ($requests->hasPages())
                <div class="card-footer bg-transparent border-0 py-3">
                    {{ $requests->links() }}
                </div>
            @endif
        </div>
    </div>
</section>

{{-- Detail modal --}}
<div class="modal fade" id="detailModal" tabindex="-1" role="dialog" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius:1.25rem; overflow:hidden;">
            <div class="detail-modal-header p-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <div class="detail-icon-wrap mr-3">
                            <i class="fas fa-file-invoice-dollar fa-lg text-white"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 text-white font-weight-bold" id="detailModalLabel">{{ __('Withdrawal Details') }}</h5>
                            <small class="text-white-50" id="detail-id-label">#</small>
                        </div>
                    </div>
                    <button type="button" class="close text-white opacity-75" data-dismiss="modal" aria-label="Close" style="font-size:1.5rem; line-height:1;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            </div>
            <div class="modal-body p-4">
                {{-- Amount banner --}}
                <div class="detail-amount-banner mb-4">
                    <div class="text-muted small mb-1">{{ __('Requested Amount') }}</div>
                    <div class="detail-amount font-weight-bold" id="detail-amount"></div>
                    <div class="mt-1" id="detail-status-badge"></div>
                </div>

                {{-- Vendor info --}}
                <div class="detail-section mb-3">
                    <div class="detail-section-title">{{ __('Vendor Information') }}</div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fas fa-user mr-1 text-muted"></i>{{ __('Name') }}</span>
                        <span class="detail-value font-weight-medium" id="detail-name"></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fas fa-tag mr-1 text-muted"></i>{{ __('Type') }}</span>
                        <span class="detail-value text-capitalize" id="detail-type"></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fas fa-calendar mr-1 text-muted"></i>{{ __('Requested') }}</span>
                        <span class="detail-value" id="detail-created"></span>
                    </div>
                </div>

                {{-- Payout account --}}
                <div class="detail-section mb-3" id="detail-payout-section">
                    <div class="detail-section-title">{{ __('Payout Account') }}</div>
                    <div class="payout-card">
                        <div class="payout-card-inner">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-university fa-lg text-primary mr-2"></i>
                                <span class="font-weight-bold text-dark" id="detail-bank-name"></span>
                            </div>
                            <div class="detail-row border-0 px-0 py-1">
                                <span class="detail-label">{{ __('Account Name') }}</span>
                                <span class="detail-value font-weight-medium" id="detail-account-name"></span>
                            </div>
                            <div class="detail-row border-0 px-0 py-1">
                                <span class="detail-label">{{ __('Account Number') }}</span>
                                <span class="detail-value font-weight-bold text-primary" id="detail-account-number"></span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Payment / Rejection info (conditionally shown) --}}
                <div class="detail-section" id="detail-extra-section" style="display:none;">
                    <div class="detail-section-title" id="detail-extra-title"></div>
                    <div class="detail-row" id="detail-ref-row" style="display:none;">
                        <span class="detail-label"><i class="fas fa-hashtag mr-1 text-muted"></i>{{ __('Reference') }}</span>
                        <span class="detail-value font-weight-medium" id="detail-ref"></span>
                    </div>
                    <div class="detail-row" id="detail-notes-row" style="display:none;">
                        <span class="detail-label"><i class="fas fa-sticky-note mr-1 text-muted"></i>{{ __('Notes') }}</span>
                        <span class="detail-value" id="detail-notes"></span>
                    </div>
                    <div class="detail-row" id="detail-reason-row" style="display:none;">
                        <span class="detail-label"><i class="fas fa-info-circle mr-1 text-muted"></i>{{ __('Reason') }}</span>
                        <span class="detail-value text-danger" id="detail-reason"></span>
                    </div>
                    <div class="detail-row" id="detail-paid-at-row" style="display:none;">
                        <span class="detail-label"><i class="fas fa-check-circle mr-1 text-muted"></i>{{ __('Paid at') }}</span>
                        <span class="detail-value" id="detail-paid-at"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 pb-4 px-4">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>

{{-- Reject modal --}}
<div class="modal fade" id="rejectModal" tabindex="-1" role="dialog" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-xl">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title font-weight-bold" id="rejectModalLabel">{{ __('Reject withdrawal request') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <form id="rejectForm" method="post" action="">
                @csrf
                <div class="modal-body">
                    <label class="font-weight-medium">{{ __('Reason (optional)') }}</label>
                    <textarea name="rejected_reason" class="form-control rounded-lg" rows="3" placeholder="{{ __('Reason for rejection...') }}"></textarea>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4">{{ __('Reject') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Mark as paid modal --}}
<div class="modal fade" id="markPaidModal" tabindex="-1" role="dialog" aria-labelledby="markPaidModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-xl">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title font-weight-bold" id="markPaidModalLabel">{{ __('Mark as paid') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <form id="markPaidForm" method="post" action="">
                @csrf
                {{-- Payout summary for admin reference --}}
                <div class="modal-body pb-0">
                    <div class="payout-summary-card mb-4" id="markpaid-payout-summary">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-university text-primary mr-2"></i>
                            <span class="font-weight-bold" id="markpaid-bank-name"></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="text-muted small">{{ __('Account Name') }}</div>
                                <div class="font-weight-medium" id="markpaid-account-name"></div>
                            </div>
                            <div class="text-right">
                                <div class="text-muted small">{{ __('Account Number') }}</div>
                                <div class="font-weight-bold text-primary" id="markpaid-account-number"></div>
                            </div>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">{{ __('Amount to transfer') }}</span>
                            <span class="font-weight-bold text-success" id="markpaid-amount"></span>
                        </div>
                    </div>
                </div>
                <div class="modal-body pt-0">
                    <div class="form-group">
                        <label class="font-weight-medium">{{ __('Payment reference') }}</label>
                        <input type="text" name="payment_reference" class="form-control rounded-lg" placeholder="{{ __('e.g. bank transfer ref') }}">
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-medium">{{ __('Notes') }}</label>
                        <textarea name="notes" class="form-control rounded-lg" rows="2" placeholder="{{ __('Optional notes...') }}"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4"><i class="fas fa-check mr-1"></i> {{ __('Mark as paid') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('css')
<style>
    .rounded-xl { border-radius: 1rem !important; }
    .rounded-lg { border-radius: .75rem !important; }
    .withdrawal-stat-card { min-height: 100px; }
    .withdrawal-stat-pending  { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .withdrawal-stat-approved { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
    .withdrawal-stat-rejected { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
    .withdrawal-stat-paid     { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
    .withdrawal-stat-icon     { background: rgba(255,255,255,0.25); }

    /* Detail modal */
    .detail-modal-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    }
    .detail-icon-wrap {
        width: 44px; height: 44px;
        background: rgba(255,255,255,0.2);
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
    }
    .detail-amount-banner {
        background: #f8fafc;
        border-radius: .75rem;
        padding: 1rem 1.25rem;
        border: 1px solid #e2e8f0;
    }
    .detail-amount { font-size: 1.75rem; color: #1e3a8a; }
    .detail-section-title {
        font-size: .7rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .08em; color: #94a3b8; margin-bottom: .5rem;
    }
    .detail-section {
        border-top: 1px solid #f1f5f9;
        padding-top: .75rem;
    }
    .detail-row {
        display: flex; justify-content: space-between; align-items: flex-start;
        padding: .35rem 0;
        border-bottom: 1px dashed #f1f5f9;
    }
    .detail-row:last-child { border-bottom: none; }
    .detail-label { font-size: .82rem; color: #64748b; }
    .detail-value { font-size: .85rem; color: #1e293b; text-align: right; max-width: 60%; }

    /* Payout card in detail modal */
    .payout-card {
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border-radius: .75rem; border: 1px solid #bfdbfe;
        padding: .75rem 1rem;
    }
    .payout-card-inner {}

    /* Payout summary in mark-paid modal */
    .payout-summary-card {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: .75rem;
        padding: 1rem 1.25rem;
    }

    /* Table payout details cell */
    .payout-details-cell { max-width: 220px; }
    .payout-icon-wrap { color: #3b82f6; }

    /* Status badges in detail modal */
    .detail-status-pending  { background: #fef3c7; color: #92400e; }
    .detail-status-approved { background: #dbeafe; color: #1e40af; }
    .detail-status-rejected { background: #fee2e2; color: #991b1b; }
    .detail-status-paid     { background: #d1fae5; color: #065f46; }
    .detail-status-badge {
        display: inline-block; padding: .2rem .75rem;
        border-radius: 99px; font-size: .75rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .05em;
    }
</style>
@endsection

@section('js')
<script>
$(function() {
    // Reject modal
    $('#rejectModal').on('show.bs.modal', function(e) {
        var id = $(e.relatedTarget).data('id');
        $('#rejectForm').attr('action', '{{ url("withdrawal_requests") }}/' + id + '/reject');
    });

    // Mark as paid modal — populate payout summary
    $('#markPaidModal').on('show.bs.modal', function(e) {
        var btn = $(e.relatedTarget);
        var id = btn.data('id');
        $('#markPaidForm').attr('action', '{{ url("withdrawal_requests") }}/' + id + '/mark-as-paid');
        $('#markpaid-bank-name').text(btn.data('bank-name') || '—');
        $('#markpaid-account-name').text(btn.data('account-name') || '—');
        $('#markpaid-account-number').text(btn.data('account-number') || '—');
        $('#markpaid-amount').text(btn.data('amount') || '—');
    });

    // Detail modal
    $('#detailModal').on('show.bs.modal', function(e) {
        var btn = $(e.relatedTarget);
        var status = btn.data('status');

        $('#detail-id-label').text('#' + btn.data('id'));
        $('#detail-amount').text(btn.data('amount'));
        $('#detail-name').text(btn.data('name'));
        $('#detail-type').text(btn.data('type'));
        $('#detail-created').text(btn.data('created'));
        $('#detail-account-name').text(btn.data('account-name'));
        $('#detail-account-number').text(btn.data('account-number'));
        $('#detail-bank-name').text(btn.data('bank-name'));

        // Status badge
        var statusLabels = { pending: 'Pending', approved: 'Approved', rejected: 'Rejected', paid: 'Paid' };
        $('#detail-status-badge').html(
            '<span class="detail-status-badge detail-status-' + status + '">' + (statusLabels[status] || status) + '</span>'
        );

        // Extra info section
        var ref = btn.data('ref') || '';
        var notes = btn.data('notes') || '';
        var reason = btn.data('rejected-reason') || '';
        var paidAt = btn.data('paid-at') || '';
        var hasExtra = ref || notes || reason || paidAt;

        if (hasExtra) {
            $('#detail-extra-section').show();
            if (status === 'rejected') {
                $('#detail-extra-title').text('Rejection Details');
            } else if (status === 'paid') {
                $('#detail-extra-title').text('Payment Details');
            } else {
                $('#detail-extra-title').text('Additional Info');
            }
            if (ref) { $('#detail-ref').text(ref); $('#detail-ref-row').show(); } else { $('#detail-ref-row').hide(); }
            if (notes) { $('#detail-notes').text(notes); $('#detail-notes-row').show(); } else { $('#detail-notes-row').hide(); }
            if (reason) { $('#detail-reason').text(reason); $('#detail-reason-row').show(); } else { $('#detail-reason-row').hide(); }
            if (paidAt) { $('#detail-paid-at').text(paidAt); $('#detail-paid-at-row').show(); } else { $('#detail-paid-at-row').hide(); }
        } else {
            $('#detail-extra-section').hide();
        }
    });
});
</script>
@endsection
