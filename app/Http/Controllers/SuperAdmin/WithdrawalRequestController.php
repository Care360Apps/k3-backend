<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Lab;
use App\Models\LabSettle;
use App\Models\Pharmacy;
use App\Models\PharmacySettle;
use App\Models\Doctor;
use App\Models\Settle;
use App\Models\Setting;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WithdrawalRequestController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->user()?->hasRole('super admin'), Response::HTTP_FORBIDDEN, '403 Forbidden');

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $query = WithdrawalRequest::with(['holder', 'approver'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('holder_type')) {
            $query->where('holder_type', $request->holder_type);
        }

        $requests = $query->paginate(15)->withQueryString();

        $currency = Setting::first()->currency_symbol ?? '';

        $counts = [
            'pending' => WithdrawalRequest::where('status', WithdrawalRequest::STATUS_PENDING)->count(),
            'approved' => WithdrawalRequest::where('status', WithdrawalRequest::STATUS_APPROVED)->count(),
            'rejected' => WithdrawalRequest::where('status', WithdrawalRequest::STATUS_REJECTED)->count(),
            'paid' => WithdrawalRequest::where('status', WithdrawalRequest::STATUS_PAID)->count(),
        ];

        return view('superAdmin.withdrawal.index', compact('requests', 'currency', 'counts'));
    }

    public function approve(int $id)
    {
        $wr = WithdrawalRequest::findOrFail($id);
        if ($wr->status !== WithdrawalRequest::STATUS_PENDING) {
            return redirect()->route('withdrawal_requests.index')
                ->with('error', __('Request cannot be approved.'));
        }
        $wr->update([
            'status' => WithdrawalRequest::STATUS_APPROVED,
            'approved_by' => auth()->id(),
        ]);
        return redirect()->route('withdrawal_requests.index')
            ->with('status', __('Withdrawal request approved.'));
    }

    public function reject(Request $request, int $id)
    {
        $request->validate(['rejected_reason' => 'nullable|string|max:500']);
        $wr = WithdrawalRequest::findOrFail($id);
        if ($wr->status !== WithdrawalRequest::STATUS_PENDING) {
            return redirect()->route('withdrawal_requests.index')
                ->with('error', __('Request cannot be rejected.'));
        }
        $wr->update([
            'status' => WithdrawalRequest::STATUS_REJECTED,
            'rejected_reason' => $request->rejected_reason,
            'approved_by' => auth()->id(),
        ]);
        return redirect()->route('withdrawal_requests.index')
            ->with('status', __('Withdrawal request rejected.'));
    }

    public function markAsPaid(Request $request, int $id)
    {
        $request->validate([
            'payment_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);
        $wr = WithdrawalRequest::findOrFail($id);
        if ($wr->status !== WithdrawalRequest::STATUS_APPROVED) {
            return redirect()->route('withdrawal_requests.index')
                ->with('error', __('Only approved requests can be marked as paid.'));
        }

        DB::transaction(function () use ($wr, $request) {
            $wr->update([
                'status' => WithdrawalRequest::STATUS_PAID,
                'paid_at' => now(),
                'payment_reference' => $request->payment_reference,
                'notes' => $request->notes,
            ]);

            $holderType = $wr->holder_type;
            $holderId = $wr->holder_id;

            if ($holderType === WithdrawalRequest::HOLDER_DOCTOR || $holderType === WithdrawalRequest::HOLDER_PROVIDER) {
                Settle::where('doctor_id', $holderId)->where('doctor_status', 0)->update(['doctor_status' => 1]);
            }
            if ($holderType === WithdrawalRequest::HOLDER_PHARMACY) {
                PharmacySettle::where('pharmacy_id', $holderId)->where('pharmacy_status', 0)->update(['pharmacy_status' => 1]);
            }
            if ($holderType === WithdrawalRequest::HOLDER_LAB) {
                LabSettle::where('lab_id', $holderId)->where('lab_status', 0)->update(['lab_status' => 1]);
            }
        });

        return redirect()->route('withdrawal_requests.index')
            ->with('status', __('Withdrawal marked as paid.'));
    }
}
