<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\DoctorSubscription;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\PaymentGatewayService;
use App\Services\PaymentReceiptClaimService;
use App\Services\SubscriptionCheckoutReference;
use Carbon\Carbon;
use Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;
use Symfony\Component\HttpFoundation\Response;

class DoctorSubscriptionController extends Controller
{
    public function doctor_subscription()
    {
        abort_if(Gate::denies('doctor_subscription'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $subscriptions = Subscription::orderBy('id', 'DESC')->get();
        $currency = Setting::first()->currency_symbol;
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        $purchase = DoctorSubscription::where([['doctor_id', $doctor->id], ['status', 1]])->first();

        return view('doctor.subscription.subscription', compact('subscriptions', 'currency', 'purchase'));
    }

    public function subscription_purchase($subscription_id)
    {
        abort_if(Gate::denies('doctor_subscription_purchase'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $subscription = Subscription::find($subscription_id);
        $setting = Setting::first();

        return view('doctor.subscription.purchase_subscription', compact('subscription', 'setting'));
    }

    public function purchase(Request $request)
    {
        $request->validate([
            'subscription_id' => 'bail|required|integer',
            'duration' => 'bail|required|integer|min:1',
            'payment_type' => 'bail|required',
            'payment_token' => 'bail|nullable|string|max:512',
        ]);
        $doctor = Doctor::where('user_id', auth()->id())->firstOrFail();
        $plan = collect(json_decode(Subscription::findOrFail($request->subscription_id)->plan, true))
            ->first(fn ($entry) => (int) ($entry['month'] ?? 0) === (int) $request->duration);
        if (! $plan) {
            throw ValidationException::withMessages(['duration' => __('Invalid subscription duration.')]);
        }

        // The server, not the browser, determines the subscription price and
        // paid state. An unpaid attempt cannot replace the current paid plan.
        $data = $request->only(['subscription_id', 'duration', 'payment_type', 'payment_token']);
        $data['amount'] = (float) $plan['price'];
        $data = (new PaymentGatewayService)->resolve($data, DoctorSubscription::class, auth()->id());
        $paid = (int) ($data['payment_status'] ?? 0) === 1;
        $data['doctor_id'] = $doctor->id;
        $data['start_date'] = Carbon::now(env('timezone'))->format('Y-m-d');
        $data['end_date'] = Carbon::now(env('timezone'))->addMonths($data['duration'])->format('Y-m-d');
        $data['status'] = $paid ? 1 : 0;

        $subscription = DB::transaction(function () use ($data, $doctor, $paid) {
            // Serialize subscription activation against a later gateway callback
            // and the corresponding API purchase flow for this doctor.
            Doctor::whereKey($doctor->id)->lockForUpdate()->firstOrFail();
            $created = DoctorSubscription::create($data);
            (new PaymentReceiptClaimService())->claim(
                $data, 'subscription', (int) $created->id, (int) $doctor->user_id
            );
            if ($paid) {
                DoctorSubscription::where('doctor_id', $doctor->id)
                    ->where('id', '!=', $created->id)
                    ->where('status', 1)->update(['status' => 0]);
                $doctor->update(['subscription_status' => 1]);
            }
            return $created;
        });

        if ($subscription->payment_type == 'FLUTTERWAVE' && ! $paid) {
            return response(['success' => true, 'url' => url('subscription_flutter/'.$subscription->id)]);
        }

        return response(['success' => true]);
    }

    public function subscription_flutter($subscription_id)
    {
        $subscription = $this->ownSubscription($subscription_id);
        if ((int) $subscription->payment_status === 1) {
            return redirect('/subscription');
        }
        $subscription->customer = auth()->user();
        // A unique, server-signed checkout reference binds this transaction
        // to the pending subscription, owner and authoritative amount.
        $txRef = SubscriptionCheckoutReference::forSubscription(
            (int) $subscription->id, (int) auth()->id(), (float) $subscription->amount
        );

        return view('doctor.subscription.flutter', compact('subscription', 'txRef'));
    }

    public function subscription_flutter_verify(Request $request, $subscription_id)
    {
        $subscription = $this->ownSubscription($subscription_id);
        if ((int) $subscription->payment_status === 1 || ! $request->filled('transaction_id')) {
            return redirect('/subscription');
        }

        $expectedRef = SubscriptionCheckoutReference::forSubscription(
            (int) $subscription->id, (int) auth()->id(), (float) $subscription->amount
        );
        $data = (new PaymentGatewayService)->resolve([
            'payment_type' => 'FLUTTERWAVE',
            'payment_token' => (string) $request->input('transaction_id'),
            'amount' => (float) $subscription->amount,
        ], DoctorSubscription::class, auth()->id(), null, $expectedRef);
        if ((int) ($data['payment_status'] ?? 0) !== 1) {
            throw ValidationException::withMessages([
                'payment_token' => __('Payment could not be verified.'),
            ]);
        }

        DB::transaction(function () use ($subscription_id, $data) {
            $doctor = Doctor::where('user_id', auth()->id())->lockForUpdate()->firstOrFail();
            $pending = DoctorSubscription::where('doctor_id', $doctor->id)
                ->whereKey($subscription_id)->lockForUpdate()->firstOrFail();
            if ((int) $pending->payment_status === 1) {
                // Duplicate success callbacks must never reassign payment
                // ownership or modify an already verified subscription.
                return;
            }
            (new PaymentReceiptClaimService())->claim(
                $data, 'subscription', (int) $pending->id, (int) $doctor->user_id
            );
            DoctorSubscription::where('doctor_id', $doctor->id)
                ->where('id', '!=', $pending->id)
                ->where('status', 1)->update(['status' => 0]);
            $pending->update([
                'payment_token' => $data['payment_token'],
                'payment_status' => 1,
                'status' => 1,
            ]);
            $doctor->update(['subscription_status' => 1]);
        });

        return redirect('/subscription');
    }

    public function stripePayment(Request $request)
    {
        $request->validate(['amount' => 'bail|required|numeric|min:0.5', 'stripeToken' => 'bail|required|string']);
        $setting = Setting::find(1);
        $stripe = new StripeClient($setting->stripe_secret_key);
        // What is actually owed is checked when the charge id is submitted with the booking/purchase.
        $charge = $stripe->charges->create([
            'amount' => (int) round((float) $request->amount * 100),
            'currency' => strtolower($setting->currency_code),
            'source' => $request->stripeToken,
            // Later charge verification requires current account ownership.
            'metadata' => ['user_id' => (string) auth()->id()],
        ]);

        return response(['success' => true, 'data' => $charge->id]);
    }

    private function ownSubscription($subscriptionId): DoctorSubscription
    {
        $doctor = Doctor::where('user_id', auth()->id())->firstOrFail();

        return DoctorSubscription::where('doctor_id', $doctor->id)->findOrFail($subscriptionId);
    }
}
