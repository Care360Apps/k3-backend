<?php

namespace Tests\Feature;

use App\Http\Controllers\DoctorApiController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaystackCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!app()->environment('testing')) $this->markTestSkipped('Synthetic test only.');
        config()->set('database.default','sqlite');
        config()->set('database.connections.sqlite.database',':memory:');
        DB::purge('sqlite');
        Schema::create('settings',function(Blueprint $t){$t->id();$t->string('currency_code');});
        DB::table('settings')->insert(['id'=>1,'currency_code'=>'NGN']);
        config()->set('services.paystack.secret','sk_test_synthetic_not_a_real_key');
        config()->set('app.url','https://care360.example.test');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void { DB::disconnect('sqlite'); parent::tearDown(); }

    private function request(array $data=[]): Request
    {
        $r=Request::create('/api/paystack_init','POST',array_merge(['amount'=>100.50,'amount_unit'=>'major','currency'=>'NGN','email'=>'untrusted@care360.example.test'],$data));
        $user=new User(['name'=>'Fictional payer','email'=>'payer@care360.example.test']);$user->id=7;
        $r->setUserResolver(fn($guard=null)=>$user);
        return $r;
    }

    private function payload(array $override=[]): array
    {
        return ['status'=>true,'data'=>array_merge(['access_code'=>'synthetic_access','reference'=>'synthetic-reference',
            'authorization_url'=>'https://checkout.paystack.com/synthetic'],$override)];
    }

    public function test_major_units_are_converted_once_and_identity_is_server_derived(): void
    {
        Http::fake(['api.paystack.co/*'=>Http::response($this->payload())]);
        $response=(new DoctorApiController)->apiPaystackInit($this->request());
        $this->assertSame(200,$response->getStatusCode());
        $this->assertSame('https://care360.example.test/api/paystack/return',json_decode($response->getContent(),true)['data']['callback_url']);
        Http::assertSent(fn($r)=>$r['amount']===10050 && $r['currency']==='NGN'
            && $r['email']==='payer@care360.example.test' && $r['metadata']['user_id']==='7');
    }

    public function test_wrong_currency_and_missing_secret_do_not_call_gateway(): void
    {
        Http::fake();
        $this->assertSame(422,(new DoctorApiController)->apiPaystackInit($this->request(['currency'=>'USD']))->getStatusCode());
        config()->set('services.paystack.secret',null);
        $this->assertSame(503,(new DoctorApiController)->apiPaystackInit($this->request())->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_existing_plus_clients_keep_minor_units_without_a_new_field(): void
    {
        Http::fake(['api.paystack.co/*'=>Http::response($this->payload())]);
        $request=$this->request(['amount'=>10050]);$request->request->remove('amount_unit');
        $this->assertSame(200,(new DoctorApiController)->apiPaystackInit($request)->getStatusCode());
        Http::assertSent(fn($r)=>$r['amount']===10050);
    }

    public function test_missing_account_identity_is_denied(): void
    {
        Http::fake();$r=$this->request();$r->setUserResolver(fn()=>null);
        $this->assertSame(403,(new DoctorApiController)->apiPaystackInit($r)->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_malicious_or_incomplete_checkout_response_is_rejected(): void
    {
        foreach(['https://checkout.paystack.com.evil.example/test','http://checkout.paystack.com/test','https://user:pass@checkout.paystack.com/test','https://checkout.paystack.com:444/test','https://checkout.paystack.com/test#fragment'] as $url){
            Http::fake(['api.paystack.co/*'=>Http::response($this->payload(['authorization_url'=>$url]))]);
            $this->assertSame(502,(new DoctorApiController)->apiPaystackInit($this->request())->getStatusCode());
        }
        Http::fake(['api.paystack.co/*'=>Http::response($this->payload(['reference'=>'']))]);
        $this->assertSame(502,(new DoctorApiController)->apiPaystackInit($this->request())->getStatusCode());
    }

    public function test_failed_gateway_status_is_not_treated_as_initialized(): void
    {
        Http::fake(['api.paystack.co/*'=>Http::response(['status'=>false,'data'=>$this->payload()['data']])]);
        $this->assertSame(502,(new DoctorApiController)->apiPaystackInit($this->request())->getStatusCode());
    }

    public function test_timeout_returns_safe_error_without_exception_details(): void
    {
        Http::fake(fn()=>throw new \Illuminate\Http\Client\ConnectionException('Private upstream details must stay private'));
        $response=(new DoctorApiController)->apiPaystackInit($this->request());
        $this->assertSame(502,$response->getStatusCode());
        $this->assertStringNotContainsString('Private upstream',$response->getContent());
    }
}
