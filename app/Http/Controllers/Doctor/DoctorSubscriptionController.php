<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\DoctorSubscription;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\PaymentGatewayService;
use Carbon\Carbon;
use Gate;
use Illuminate\Http\Request;
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
        ]);
        $doctor = Doctor::where('user_id', auth()->user()->id)->firstOrFail();
        $plan = collect(json_decode(Subscription::findOrFail($request->subscription_id)->plan, true))
            ->first(fn ($entry) => (int) ($entry['month'] ?? 0) === (int) $request->duration);
        if (! $plan) {
            throw ValidationException::withMessages(['duration' => __('Invalid subscription duration.')]);
        }
        $data = $request->only(['subscription_id', 'duration', 'payment_type', 'payment_token']);
        $data['amount'] = (float) $plan['price'];
        $data = (new PaymentGatewayService)->resolve($data, DoctorSubscription::class, auth()->id());
        $data['doctor_id'] = $doctor->id;
        $data['start_date'] = Carbon::now(env('timezone'))->format('Y-m-d');
        $data['end_date'] = Carbon::now(env('timezone'))->addMonths($data['duration'])->format('Y-m-d');
        $data['status'] = 1;
        DoctorSubscription::where([['status', 1], ['doctor_id', $doctor->id]])->update(['status' => 0]);
        $subscription = DoctorSubscription::create($data);
        if ($subscription->payment_status == 1) {
            $doctor->update(['subscription_status' => 1]);
        }
        if ($subscription->payment_type == 'FLUTTERWAVE' && $subscription->payment_status != 1) {
            return response(['success' => true, 'url' => url('subscription_flutter/'.$subscription->id)]);
        }

        return response(['success' => true]);
    }

    public function subscription_flutter($subscription_id)
    {
        $subscription = $this->ownSubscription($subscription_id);
        $subscription->customer = auth()->user();

        return view('doctor.subscription.flutter', compact('subscription'));
    }

    public function subscription_flutter_verify(Request $request, $subscription_id)
    {
        $subscription = $this->ownSubscription($subscription_id);
        if ($subscription->payment_status == 1 || ! $request->filled('transaction_id')) {
            return redirect('/subscription');
        }
        $data = (new PaymentGatewayService)->resolve([
            'payment_type' => 'FLUTTERWAVE',
            'payment_token' => (string) $request->input('transaction_id'),
            'amount' => (float) $subscription->amount,
        ], DoctorSubscription::class, auth()->id());
        $subscription->update(['payment_token' => $data['payment_token'], 'payment_status' => 1]);
        Doctor::where('user_id', auth()->id())->firstOrFail()->update(['subscription_status' => 1]);

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
        ]);

        return response(['success' => true, 'data' => $charge->id]);
    }

    private function ownSubscription($subscriptionId): DoctorSubscription
    {
        $doctor = Doctor::where('user_id', auth()->id())->firstOrFail();

        return DoctorSubscription::where('doctor_id', $doctor->id)->findOrFail($subscriptionId);
    }
}
