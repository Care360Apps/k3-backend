@extends('layout.mainlayout_admin', ['activePage' => 'withdrawal'])

@section('title', __('Withdrawal'))

@section('content')
<section class="section">
    @include('layout.breadcrumb', ['title' => __('Withdrawal')])

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 rounded-xl">
            {{ session('status') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 rounded-xl">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    <div class="row">
        {{-- Balance + Request Card --}}
        <div class="col-lg-5 mb-4">

            {{-- Available Balance --}}
            <div class="wd-balance-card mb-4">
                <div class="wd-balance-label">Available Balance</div>
                <div class="wd-balance-amount">{{ $currency }}{{ number_format($availableBalance, 2) }}</div>
                <div class="wd-balance-note">Minimum withdrawal: {{ $currency }}1.00</div>
                <div class="wd-wallet-icon"><i class="fas fa-wallet"></i></div>
            </div>

            {{-- Payout Account --}}
            <div class="card border-0 shadow-sm rounded-xl mb-4">
                <div class="card-header bg-transparent border-0 pb-0 pt-4 px-4">
                    <div class="d-flex align-items-center justify-content-between">
                        <h6 class="font-weight-bold mb-0"><i class="fas fa-university mr-2 text-primary"></i>Payout Account</h6>
                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3" data-toggle="modal" data-target="#payoutModal">
                            {{ $payoutAccount ? 'Edit' : 'Add Account' }}
                        </button>
                    </div>
                </div>
                <div class="card-body px-4 py-3">
                    @if ($payoutAccount)
                        <div class="payout-info-row">
                            <span class="payout-info-label">Bank</span>
                            <span class="payout-info-value font-weight-bold">{{ $payoutAccount->bank_name }}</span>
                        </div>
                        <div class="payout-info-row">
                            <span class="payout-info-label">Account Name</span>
                            <span class="payout-info-value">{{ $payoutAccount->account_name }}</span>
                        </div>
                        <div class="payout-info-row border-0">
                            <span class="payout-info-label">Account Number</span>
                            <span class="payout-info-value font-weight-bold text-primary">{{ $payoutAccount->account_number }}</span>
                        </div>
                    @else
                        <div class="text-center py-3">
                            <i class="fas fa-exclamation-circle fa-2x text-warning mb-2 d-block"></i>
                            <p class="text-muted mb-2 small">No payout account set.</p>
                            <button class="btn btn-primary btn-sm rounded-pill px-4" data-toggle="modal" data-target="#payoutModal">
                                <i class="fas fa-plus mr-1"></i> Add Bank Account
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Request Withdrawal --}}
            <div class="card border-0 shadow-sm rounded-xl">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h6 class="font-weight-bold mb-0"><i class="fas fa-paper-plane mr-2 text-success"></i>Request Withdrawal</h6>
                </div>
                <div class="card-body px-4 pb-4">
                    @if (! $payoutAccount)
                        <div class="alert alert-warning rounded-xl border-0 small mb-0">
                            <i class="fas fa-info-circle mr-1"></i> Add a payout account above before requesting a withdrawal.
                        </div>
                    @elseif ($availableBalance <= 0)
                        <div class="alert alert-info rounded-xl border-0 small mb-0">
                            <i class="fas fa-info-circle mr-1"></i> No balance available for withdrawal yet.
                        </div>
                    @else
                        <form method="POST" action="{{ route('lab.withdrawal.store') }}">
                            @csrf
                            <div class="form-group mb-3">
                                <label class="font-weight-medium small">Amount <span class="text-muted">(max {{ $currency }}{{ number_format($availableBalance, 2) }})</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text rounded-left-xl border-right-0 bg-light">{{ $currency }}</span>
                                    </div>
                                    <input type="number" name="amount" step="0.01" min="1" max="{{ $availableBalance }}"
                                        class="form-control border-left-0 rounded-right-xl @error('amount') is-invalid @enderror"
                                        placeholder="0.00" required>
                                    @error('amount')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success rounded-pill px-4 w-100">
                                <i class="fas fa-paper-plane mr-2"></i>Submit Request
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- Withdrawal History --}}
        <div class="col-lg-7 mb-4">
            <div class="card border-0 shadow-sm rounded-xl overflow-hidden h-100">
                <div class="card-header bg-transparent border-0 py-4 px-4">
                    <h6 class="font-weight-bold mb-0"><i class="fas fa-history mr-2 text-secondary"></i>Withdrawal History</h6>
                </div>
                <div class="card-body p-0">
                    @if ($withdrawals->isEmpty())
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fa-3x mb-3 d-block opacity-50"></i>
                            <p class="mb-0">No withdrawal requests yet.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th class="border-0 py-3 pl-4">#</th>
                                        <th class="border-0 py-3">Amount</th>
                                        <th class="border-0 py-3">Payout Account</th>
                                        <th class="border-0 py-3">Status</th>
                                        <th class="border-0 py-3">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($withdrawals as $wr)
                                    <tr>
                                        <td class="pl-4 align-middle">{{ $loop->iteration }}</td>
                                        <td class="align-middle font-weight-bold text-success">{{ $currency }}{{ number_format($wr->amount, 2) }}</td>
                                        <td class="align-middle">
                                            @if ($wr->payout_account_name)
                                                <div class="small">
                                                    <div class="font-weight-semibold">{{ $wr->payout_account_name }}</div>
                                                    <div class="text-muted">{{ $wr->payout_bank_name }} &bull; {{ $wr->payout_account_number }}</div>
                                                </div>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="align-middle">
                                            @if ($wr->status === 'pending')
                                                <span class="badge badge-pill badge-warning px-3 py-2">Pending</span>
                                            @elseif ($wr->status === 'approved')
                                                <span class="badge badge-pill badge-info px-3 py-2">Approved</span>
                                            @elseif ($wr->status === 'rejected')
                                                <span class="badge badge-pill badge-danger px-3 py-2">Rejected</span>
                                                @if ($wr->rejected_reason)
                                                    <div class="small text-danger mt-1">{{ $wr->rejected_reason }}</div>
                                                @endif
                                            @else
                                                <span class="badge badge-pill badge-success px-3 py-2">Paid</span>
                                                @if ($wr->payment_reference)
                                                    <div class="small text-muted mt-1">Ref: {{ $wr->payment_reference }}</div>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="align-middle text-muted small">{{ $wr->created_at->format('M d, Y H:i') }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>

{{-- Payout Account Modal --}}
<div class="modal fade" id="payoutModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius:1.25rem;overflow:hidden;">
            <div class="payout-modal-header p-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <div class="payout-modal-icon mr-3">
                            <i class="fas fa-university fa-lg text-white"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 text-white font-weight-bold">{{ $payoutAccount ? 'Edit' : 'Add' }} Payout Account</h5>
                            <small class="text-white-50">Bank details for receiving payouts</small>
                        </div>
                    </div>
                    <button type="button" class="close text-white" data-dismiss="modal" style="opacity:.75;font-size:1.5rem;"><span>&times;</span></button>
                </div>
            </div>
            <form method="POST" action="{{ route('lab.payout.update') }}">
                @csrf
                <div class="modal-body p-4">
                    <div class="form-group">
                        <label class="font-weight-medium small">
                            <i class="fas fa-user mr-1 text-primary"></i> Account Name
                        </label>
                        <input type="text" name="account_name" class="form-control rounded-xl"
                            value="{{ $payoutAccount->account_name ?? '' }}"
                            placeholder="e.g. John Doe" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-medium small">
                            <i class="fas fa-university mr-1 text-primary"></i> Bank Name
                        </label>
                        <input type="text" name="bank_name" class="form-control rounded-xl"
                            value="{{ $payoutAccount->bank_name ?? '' }}"
                            placeholder="e.g. First Bank" required>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-medium small">
                            <i class="fas fa-tag mr-1 text-primary"></i> Account Number
                        </label>
                        <input type="text" name="account_number" class="form-control rounded-xl"
                            value="{{ $payoutAccount->account_number ?? '' }}"
                            placeholder="e.g. 0123456789" required>
                    </div>
                </div>
                <div class="modal-footer border-0 pb-4 px-4">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-5">
                        <i class="fas fa-save mr-1"></i> Save Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('css')
<style>
    .rounded-xl  { border-radius: 1rem !important; }
    .rounded-left-xl  { border-radius: 1rem 0 0 1rem !important; }
    .rounded-right-xl { border-radius: 0 1rem 1rem 0 !important; }

    /* Balance card */
    .wd-balance-card {
        position: relative; overflow: hidden;
        background: linear-gradient(135deg, #064e3b 0%, #059669 100%);
        border-radius: 1.25rem; padding: 1.5rem 1.5rem 1.25rem;
        color: #fff; box-shadow: 0 8px 24px rgba(5,150,105,.25);
    }
    .wd-balance-label { font-size:.8rem; opacity:.8; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.25rem; }
    .wd-balance-amount { font-size: 2.25rem; font-weight: 800; letter-spacing:-.5px; }
    .wd-balance-note   { font-size:.78rem; opacity:.7; margin-top:.25rem; }
    .wd-wallet-icon {
        position:absolute; right:1.25rem; top:50%; transform:translateY(-50%);
        width:52px; height:52px; background:rgba(255,255,255,.15);
        border-radius:16px; display:flex; align-items:center; justify-content:center;
        font-size:1.4rem;
    }

    /* Payout info rows */
    .payout-info-row {
        display:flex; justify-content:space-between; align-items:center;
        padding:.45rem 0; border-bottom:1px dashed #f1f5f9;
    }
    .payout-info-label { font-size:.8rem; color:#64748b; }
    .payout-info-value { font-size:.85rem; color:#1e293b; }

    /* Payout modal header */
    .payout-modal-header { background: linear-gradient(135deg,#064e3b 0%,#059669 100%); }
    .payout-modal-icon {
        width:44px; height:44px; background:rgba(255,255,255,.2);
        border-radius:14px; display:flex; align-items:center; justify-content:center;
    }
    .form-control.rounded-xl { border-radius:.75rem !important; }
</style>
@endsection
