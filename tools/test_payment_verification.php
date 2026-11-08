<?php
// Runs the real payment service against isolated fake transports/models.
// No Composer, database, credentials, or external HTTP requests are used.
namespace App\Models {
    /**
     * Shared no-database ledger for all fake Care360 payment product tables.
     */
    class SyntheticReceiptTable {
        public static bool $duplicate = false;
        public static array $claimed = [];
        private string $token = '';

        public static function where($column, $token): self {
            if ($column !== 'payment_token') throw new \RuntimeException('Unexpected query.');
            $instance = new static;
            $instance->token = (string) $token;
            return $instance;
        }
        public function exists(): bool {
            return self::$duplicate || (self::$claimed[static::class][$this->token] ?? false);
        }
    }
    class Appointment extends SyntheticReceiptTable {}
    class PurchaseMedicine extends SyntheticReceiptTable {}
    class Report extends SyntheticReceiptTable {}
    class DoctorSubscription extends SyntheticReceiptTable {}
    class Setting {
        public string $currency_code = 'NGN';
        public string $stripe_secret_key = 'synthetic';
        public string $razor_key = 'synthetic';
        public string $paypal_client_id = 'synthetic';
        public string $paypal_secret_key = 'synthetic';
        public int $isLiveKey = 0;
        public static function first(): self { return new self; }
    }
}
namespace Illuminate\Validation {
    class ValidationException extends \Exception {
        public static function withMessages(array $messages): self { return new self('Rejected'); }
    }
}
namespace Illuminate\Support\Facades {
    class Cache { public static function get($key) { return 7; } }
    class Http {
        public static array $payload = [];
        public static int $calls = 0;
        public static function withToken($token): self { return new self; }
        public static function withBasicAuth($user, $password): self { return new self; }
        public static function asForm(): self { return new self; }
        public function get($url): self { self::$calls++; return $this; }
        public function post($url, $body): self { self::$calls++; return $this; }
        public function throw(): self { return $this; }
        public function json($key = null) {
            if ($key === 'access_token') return 'synthetic';
            return $key === null ? self::$payload : \data_get(self::$payload, $key);
        }
    }
}
namespace Stripe {
    class StripeClient {
        public object $paymentIntents;
        public static array $intent = [];
        public function __construct($key) {
            $this->paymentIntents = new class {
                public function retrieve($id): object { return (object) StripeClient::$intent; }
            };
        }
    }
}
namespace {
    use App\Services\PaymentGatewayService;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Validation\ValidationException;
    use Stripe\StripeClient;

    function __($message) { return $message; }
    function info($message): void {}
    function config($key) { return $GLOBALS['gatewaySecret']; }
    function data_get($data, $path, $default = null) {
        foreach (explode('.', $path) as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) return $default;
            $data = $data[$part];
        }
        return $data;
    }
    class FakeOrder extends \App\Models\SyntheticReceiptTable {}
    require __DIR__.'/../app/Services/PaymentGatewayService.php';
    $GLOBALS['gatewaySecret'] = 'synthetic';
    $passed = 0;
    function checkCase(string $label, string $type, string $token, bool $expected): void {
        global $passed;
        $accepted = false;
        try {
            $result = (new PaymentGatewayService)->resolve([
                'payment_type' => $type, 'payment_token' => $token,
                'payment_status' => 1, 'amount' => 100,
            ], FakeOrder::class, 7, 100);
            $accepted = $result['payment_status'] === 1;
        } catch (ValidationException $e) {}
        if ($accepted !== $expected) throw new \RuntimeException($label);
        echo "PASS: $label\n"; $passed++;
    }
    checkCase('client cannot mark COD paid', 'COD', '', false);
    checkCase('client cannot mark tokenless payment paid', 'PAYSTACK', '', false);
    $GLOBALS['gatewaySecret'] = null;
    $before = Http::$calls;
    checkCase('missing gateway secret rejects payment', 'FLUTTERWAVE', 'tx1', false);
    if (Http::$calls !== $before) throw new \RuntimeException('Missing secret attempted HTTP');
    $GLOBALS['gatewaySecret'] = 'synthetic';

    StripeClient::$intent = ['id'=>'pi_1','status'=>'succeeded','currency'=>'NGN',
        'amount'=>10000,'metadata'=>['user_id'=>7]];
    checkCase('Stripe correct currency and owner accepted', 'STRIPE', 'pi_1', true);
    StripeClient::$intent['currency'] = 'USD';
    checkCase('Stripe wrong currency rejected', 'STRIPE', 'pi_1', false);
    unset(StripeClient::$intent['currency']);
    checkCase('Stripe missing currency rejected', 'STRIPE', 'pi_1', false);
    StripeClient::$intent['currency'] = 'NGN';
    StripeClient::$intent['metadata']['user_id'] = 8;
    checkCase('Stripe wrong owner rejected', 'STRIPE', 'pi_1', false);
    StripeClient::$intent['metadata']['user_id'] = 7;
    FakeOrder::$duplicate = true;
    checkCase('existing order payment token rejected', 'STRIPE', 'pi_1', false);
    FakeOrder::$duplicate = false;

    \App\Models\SyntheticReceiptTable::$claimed[\App\Models\Appointment::class]['pi_1'] = true;
    checkCase('payment reference on appointment cannot be reused for another Care360 service', 'STRIPE', 'pi_1', false);
    unset(\App\Models\SyntheticReceiptTable::$claimed[\App\Models\Appointment::class]['pi_1']);
    \App\Models\SyntheticReceiptTable::$claimed[\App\Models\PurchaseMedicine::class]['pi_1'] = true;
    checkCase('medicine receipt cannot pay second order', 'STRIPE', 'pi_1', false);
    unset(\App\Models\SyntheticReceiptTable::$claimed[\App\Models\PurchaseMedicine::class]['pi_1']);
    \App\Models\SyntheticReceiptTable::$claimed[\App\Models\Report::class]['pi_1'] = true;
    checkCase('lab receipt cannot be replayed', 'STRIPE', 'pi_1', false);
    unset(\App\Models\SyntheticReceiptTable::$claimed[\App\Models\Report::class]['pi_1']);
    \App\Models\SyntheticReceiptTable::$claimed[\App\Models\DoctorSubscription::class]['pi_1'] = true;
    checkCase('subscription receipt cannot be replayed', 'STRIPE', 'pi_1', false);
    unset(\App\Models\SyntheticReceiptTable::$claimed[\App\Models\DoctorSubscription::class]['pi_1']);

    Http::$payload = ['id'=>'PAYID-1','state'=>'approved','transactions'=>[
        ['amount'=>['total'=>'100.00','currency'=>'NGN']]]];
    checkCase('PayPal v1 correct currency accepted', 'PAYPAL', 'PAYID-1', true);
    Http::$payload['transactions'][0]['amount']['currency'] = 'USD';
    checkCase('PayPal v1 wrong currency rejected', 'PAYPAL', 'PAYID-1', false);
    unset(Http::$payload['transactions'][0]['amount']['currency']);
    checkCase('PayPal v1 missing currency rejected', 'PAYPAL', 'PAYID-1', false);

    Http::$payload = ['status'=>'authorized','currency'=>'NGN','amount'=>10000];
    checkCase('Razorpay authorization alone is unpaid', 'RAZORPAY', 'pay_1', false);
    Http::$payload['status'] = 'captured';
    checkCase('Razorpay captured payment accepted', 'RAZORPAY', 'pay_1', true);
    Http::$payload['amount'] = 9900;
    checkCase('Razorpay underpayment rejected', 'RAZORPAY', 'pay_1', false);
    echo "$passed payment regression cases passed.\n";
}
