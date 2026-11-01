<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

/**
 * All calls that need a gateway secret key happen here, so the keys never have to be sent to the apps.
 *
 * Stripe and PayPal credentials come from the settings table. Paystack, Flutterwave and Razorpay secret keys
 * are read from .env (PAYSTACK_SECRET_KEY, FLUTTERWAVE_SECRET_KEY, RAZORPAY_KEY_SECRET); when one is missing,
 * payments through that gateway are rejected.
 */
class PaymentGatewayService
{
    private Setting $setting;

    public function __construct()
    {
        $this->setting = Setting::first();
    }

    public function createStripePaymentIntent(float $amount, int $userId): array
    {
        $intent = $this->stripe()->paymentIntents->create([
            'amount' => $this->toMinorUnits($amount),
            'currency' => strtolower($this->setting->currency_code),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => ['user_id' => $userId],
        ]);

        return ['id' => $intent->id, 'client_secret' => $intent->client_secret];
    }

    public function createPaypalPayment(float $amount, string $returnUrl, string $cancelUrl, string $description, int $userId): array
    {
        $response = Http::withToken($this->paypalAccessToken())->post($this->paypalBaseUrl().'/v1/payments/payment', [
            'intent' => 'sale',
            'payer' => ['payment_method' => 'paypal'],
            'transactions' => [[
                'amount' => ['total' => number_format($amount, 2, '.', ''), 'currency' => $this->setting->currency_code],
                'description' => $description,
            ]],
            'redirect_urls' => ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl],
        ]);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['paypal' => $response->json('message') ?? __('PayPal payment could not be created.')]);
        }
        Cache::put($this->paypalOwnerKey($response->json('id')), $userId, now()->addDay());
        $approval = collect($response->json('links'))->firstWhere('rel', 'approval_url');

        return ['id' => $response->json('id'), 'approval_url' => $approval['href'] ?? null];
    }

    public function executePaypalPayment(string $paymentId, string $payerId, int $userId): array
    {
        if (Cache::get($this->paypalOwnerKey($paymentId)) != $userId) {
            $this->fail();
        }
        $response = Http::withToken($this->paypalAccessToken())
            ->post($this->paypalBaseUrl().'/v1/payments/payment/'.rawurlencode($paymentId).'/execute', ['payer_id' => $payerId]);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['paypal' => $response->json('message') ?? __('PayPal payment could not be completed.')]);
        }

        return ['id' => $response->json('id'), 'state' => $response->json('state')];
    }

    /**
     * Returns $data with payment_status/payment_token set from what the gateway confirms.
     *
     * The client-sent payment_status is never trusted: COD and gateway payments without a token are recorded as
     * unpaid; a token is accepted only when the gateway confirms it for at least $chargeAmount (defaults to amount).
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass  used to reject a payment token that was already used
     */
    public function resolve(array $data, string $modelClass, int $userId, ?float $chargeAmount = null): array
    {
        $type = strtoupper((string) ($data['payment_type'] ?? ''));
        if ($type === 'RAZOR') {
            $type = 'RAZORPAY';
        }
        $data['payment_type'] = $type;
        $token = trim((string) ($data['payment_token'] ?? ''));
        $amount = $chargeAmount ?? (float) ($data['amount'] ?? 0);
        $data['payment_status'] = 0;
        $data['payment_token'] = null;

        if (! in_array($type, ['COD', 'STRIPE', 'PAYPAL', 'PAYSTACK', 'FLUTTERWAVE', 'RAZORPAY'], true)) {
            throw ValidationException::withMessages(['payment_type' => __('Unsupported payment type.')]);
        }
        if ($type === 'COD' || $token === '') {
            return $data;
        }

        $verify = match ($type) {
            'STRIPE' => fn () => $this->verifyStripe($token, $amount, $userId),
            'PAYPAL' => fn () => $this->verifyPaypal($token, $amount, $userId),
            'PAYSTACK' => config('services.paystack.secret') ? fn () => $this->verifyPaystack($token, $amount) : null,
            'FLUTTERWAVE' => config('services.flutterwave.secret') ? fn () => $this->verifyFlutterwave($token, $amount) : null,
            'RAZORPAY' => config('services.razorpay.secret') ? fn () => $this->verifyRazorpay($token, $amount) : null,
        };
        if ($verify === null) {
            info("{$type} payment rejected: secret key not configured.");
            $this->fail();
        }

        try {
            $verifiedToken = $verify();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            info("{$type} payment verification failed: ".$e->getMessage());
            $this->fail();
        }
        if ($modelClass::where('payment_token', $verifiedToken)->exists()) {
            $this->fail();
        }
        $data['payment_token'] = $verifiedToken;
        $data['payment_status'] = 1;

        return $data;
    }

    private function verifyStripe(string $token, float $amount, int $userId): string
    {
        if (str_starts_with($token, 'pi_')) {
            $intent = $this->stripe()->paymentIntents->retrieve($token);
            $ok = $intent->status === 'succeeded'
                && $this->sameCurrency($intent->currency ?? null)
                && $intent->amount >= $this->toMinorUnits($amount)
                && (string) ($intent->metadata['user_id'] ?? '') === (string) $userId;
            if (! $ok) {
                $this->fail();
            }

            return $intent->id;
        }

        // Charges already made by the web panels' /stripePayment endpoint.
        if (str_starts_with($token, 'ch_')) {
            $charge = $this->stripe()->charges->retrieve($token);
            $ok = $charge->status === 'succeeded'
                && ! $charge->refunded
                && strtolower($charge->currency) === strtolower($this->setting->currency_code)
                && $charge->amount >= $this->toMinorUnits($amount);
            if (! $ok) {
                $this->fail();
            }

            return $charge->id;
        }

        // Card tokens (tok_...) from the card-field screens are charged here, server-side.
        $charge = $this->stripe()->charges->create([
            'amount' => $this->toMinorUnits($amount),
            'currency' => strtolower($this->setting->currency_code),
            'source' => $token,
        ]);
        if ($charge->status !== 'succeeded') {
            $this->fail();
        }

        return $charge->id;
    }

    private function verifyPaypal(string $paymentId, float $amount, int $userId): string
    {
        // The web panels use the PayPal JS SDK, which returns Orders v2 ids; the apps use v1 PAYID-... payments.
        if (! str_starts_with($paymentId, 'PAYID-')) {
            return $this->verifyPaypalOrder($paymentId, $amount);
        }
        if (Cache::get($this->paypalOwnerKey($paymentId)) != $userId) {
            $this->fail();
        }
        $payment = Http::withToken($this->paypalAccessToken())
            ->get($this->paypalBaseUrl().'/v1/payments/payment/'.rawurlencode($paymentId))
            ->throw()
            ->json();
        $paid = (float) data_get($payment, 'transactions.0.amount.total', 0);
        if (($payment['state'] ?? null) !== 'approved'
            || ! $this->sameCurrency(data_get($payment, 'transactions.0.amount.currency'))
            || $paid + 0.001 < $amount) {
            $this->fail();
        }

        return $payment['id'];
    }

    private function verifyPaypalOrder(string $orderId, float $amount): string
    {
        $order = Http::withToken($this->paypalAccessToken())
            ->get($this->paypalBaseUrl().'/v2/checkout/orders/'.rawurlencode($orderId))
            ->throw()
            ->json();
        $captured = collect($order['purchase_units'] ?? [])
            ->flatMap(fn ($unit) => data_get($unit, 'payments.captures', []))
            ->where('status', 'COMPLETED')
            ->filter(fn ($capture) => strtoupper((string) data_get($capture, 'amount.currency_code')) === strtoupper((string) $this->setting->currency_code))
            ->sum(fn ($capture) => (float) data_get($capture, 'amount.value', 0));
        if (($order['status'] ?? null) !== 'COMPLETED' || $captured + 0.001 < $amount) {
            $this->fail();
        }

        return $order['id'];
    }

    private function verifyPaystack(string $reference, float $amount): string
    {
        $data = Http::withToken(config('services.paystack.secret'))
            ->get('https://api.paystack.co/transaction/verify/'.rawurlencode($reference))
            ->throw()
            ->json('data');
        if (($data['status'] ?? null) !== 'success'
            || ! $this->sameCurrency($data['currency'] ?? null)
            || (int) ($data['amount'] ?? 0) < $this->toMinorUnits($amount)) {
            $this->fail();
        }

        return $reference;
    }

    private function verifyFlutterwave(string $transactionId, float $amount): string
    {
        $data = Http::withToken(config('services.flutterwave.secret'))
            ->get('https://api.flutterwave.com/v3/transactions/'.rawurlencode($transactionId).'/verify')
            ->throw()
            ->json('data');
        if (($data['status'] ?? null) !== 'successful'
            || ! $this->sameCurrency($data['currency'] ?? null)
            || (float) ($data['amount'] ?? 0) + 0.001 < $amount) {
            $this->fail();
        }

        return (string) $transactionId;
    }

    private function verifyRazorpay(string $paymentId, float $amount): string
    {
        $data = Http::withBasicAuth((string) $this->setting->razor_key, config('services.razorpay.secret'))
            ->get('https://api.razorpay.com/v1/payments/'.rawurlencode($paymentId))
            ->throw()
            ->json();
        if (($data['status'] ?? null) !== 'captured'
            || ! $this->sameCurrency($data['currency'] ?? null)
            || (int) ($data['amount'] ?? 0) < $this->toMinorUnits($amount)) {
            $this->fail();
        }

        return $paymentId;
    }

    private function stripe(): StripeClient
    {
        return new StripeClient($this->setting->stripe_secret_key);
    }

    private function paypalBaseUrl(): string
    {
        return $this->setting->isLiveKey == 1 ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function paypalAccessToken(): string
    {
        $token = Http::asForm()
            ->withBasicAuth((string) $this->setting->paypal_client_id, (string) $this->setting->paypal_secret_key)
            ->post($this->paypalBaseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->json('access_token');
        if (! $token) {
            throw ValidationException::withMessages(['paypal' => __('PayPal is not configured correctly.')]);
        }

        return $token;
    }

    private function paypalOwnerKey(?string $paymentId): string
    {
        return 'paypal_payment_owner:'.$paymentId;
    }

    private function sameCurrency(?string $currency): bool
    {
        return strtoupper((string) $currency) === strtoupper((string) $this->setting->currency_code);
    }

    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['payment_token' => __('Payment could not be verified.')]);
    }
}
