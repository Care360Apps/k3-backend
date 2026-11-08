<?php

namespace App\Http\Controllers;

use App\Services\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;

class PaymentApiController extends Controller
{
    public function stripePaymentIntent(Request $request, PaymentGatewayService $gateway)
    {
        $request->validate(['amount' => 'bail|required|numeric|min:0.5']);
        try {
            $intent = $gateway->createStripePaymentIntent((float) $request->amount, auth()->id());
        } catch (ApiErrorException $e) {
            // Stripe exception details may include sensitive provider context.
            // Log a fixed event label, never raw upstream error bodies.
            logger()->warning('Stripe payment intent creation failed.');
            throw ValidationException::withMessages(['stripe' => __('Stripe payment could not be started.')]);
        }

        return response(['success' => true, 'data' => $intent, 'msg' => 'Payment intent created']);
    }

    public function paypalCreatePayment(Request $request, PaymentGatewayService $gateway)
    {
        $request->validate([
            'amount' => 'bail|required|numeric|min:0.01',
            'return_url' => 'bail|required|string|max:255',
            'cancel_url' => 'bail|required|string|max:255',
            'description' => 'nullable|string|max:127',
        ]);
        $payment = $gateway->createPaypalPayment(
            (float) $request->amount,
            $request->return_url,
            $request->cancel_url,
            $request->description ?? config('app.name'),
            auth()->id()
        );

        return response(['success' => true, 'data' => $payment, 'msg' => 'PayPal payment created']);
    }

    public function paypalExecutePayment(Request $request, PaymentGatewayService $gateway)
    {
        $request->validate([
            'payment_id' => 'bail|required|string|max:100',
            'payer_id' => 'bail|required|string|max:100',
        ]);
        $payment = $gateway->executePaypalPayment($request->payment_id, $request->payer_id, auth()->id());

        return response(['success' => true, 'data' => $payment, 'msg' => 'PayPal payment executed']);
    }
}
