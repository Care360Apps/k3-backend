<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Offer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PatientDiscoveryPublicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing')) {
            $this->markTestSkipped('Synthetic testing only.');
        }
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('banner', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('image')->default('');
            $t->string('link'); $t->text('description')->nullable();
            $t->integer('status')->default(1); $t->boolean('is_sponsored')->default(false);
            $t->dateTime('starts_at')->nullable(); $t->dateTime('ends_at')->nullable();
            $t->timestamps();
        });
        Schema::create('blog', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->text('desc');
            $t->string('blog_ref'); $t->string('image')->default('');
            $t->integer('status')->default(1); $t->integer('release_now')->default(1);
            $t->timestamps();
        });
        Schema::create('offer', function (Blueprint $t) {
            $t->id();
            foreach (['name','image','offer_code','discount_type','desc','start_end_date','user_id','doctor_id'] as $field) {
                $t->text($field)->default('');
            }
            foreach (['discount','is_flat','flatDiscount','min_discount','use_count'] as $field) {
                $t->integer($field)->default(0);
            }
            $t->integer('max_use')->default(10); $t->integer('status')->default(1);
            $t->timestamps();
        });
    }

    protected function tearDown(): void { DB::disconnect('sqlite'); parent::tearDown(); }

    public function test_offer_creation_requires_explicit_permission_before_validation(): void
    {
        \Illuminate\Support\Facades\Gate::shouldReceive('denies')->once()->with('offer_add')->andReturn(true);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new \App\Http\Controllers\SuperAdmin\OfferController)->store(new \Illuminate\Http\Request);
    }

    public function test_offer_edit_requires_explicit_permission_before_validation(): void
    {
        \Illuminate\Support\Facades\Gate::shouldReceive('denies')->once()->with('offer_edit')->andReturn(true);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new \App\Http\Controllers\SuperAdmin\OfferController)->update(new \Illuminate\Http\Request, 1);
    }

    public function test_offer_delete_requires_explicit_permission_before_record_access(): void
    {
        \Illuminate\Support\Facades\Gate::shouldReceive('denies')->once()->with('offer_delete')->andReturn(true);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new \App\Http\Controllers\SuperAdmin\OfferController)->destroy(1);
    }

    public function test_campaign_status_schedule_https_and_paid_label_are_respected(): void
    {
        $this->travelTo(now()->setDate(2026,11,9)->startOfDay());
        $base=['name'=>'Synthetic campaign','image'=>'test.png','link'=>'https://example.org/service',
            'status'=>1,'is_sponsored'=>true,'starts_at'=>'2026-11-01','ends_at'=>'2026-11-09'];
        \App\Models\Banner::create($base);
        foreach ([['status'=>0],['starts_at'=>'2026-11-10'],['ends_at'=>'2026-11-08'],
            ['link'=>'http://example.org/unsafe']] as $change) {
            \App\Models\Banner::create(array_merge($base,$change));
        }
        $this->getJson('/api/banner')->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.is_sponsored',true)
            ->assertJsonPath('data.0.link','https://example.org/service');
        $this->travelBack();
    }

    public function test_banner_image_delivery_and_inactive_preview(): void
    {
        $name='banner-test-'.\Illuminate\Support\Str::uuid().'.png';
        $path=public_path('images/upload/'.$name);
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path));
        $fixture=\Illuminate\Http\UploadedFile::fake()->image('banner.png',16,16);
        try {
            $this->assertTrue(copy($fixture->getPathname(),$path));
            $banner=\App\Models\Banner::create(['name'=>'Synthetic','image'=>$name,'link'=>'https://example.org','status'=>1]);
            $url='/api/banner-images/'.$banner->id;
            $this->getJson('/api/banner')->assertJsonPath('data.0.fullImage',$banner->fullImage);
            $this->get($url)->assertOk()->assertHeader('Content-Type','image/png');
            $banner->update(['status'=>0]);
            $this->get($url)->assertNotFound();
            \Illuminate\Support\Facades\Gate::shouldReceive('allows')->with('banner_access')->andReturn(true);
            $this->assertSame(200,(new \App\Http\Controllers\BannerImageController)->preview($banner->id)->getStatusCode());
            $banner->update(['status'=>1,'image'=>'../secret.png']);
            $this->get($url)->assertNotFound();
        } finally { @unlink($path); }
    }

    public function test_only_published_articles_are_public_and_missing_ids_return_404(): void
    {
        foreach ([[1,1],[0,1],[1,0]] as [$status,$release]) {
            Blog::create(['title'=>'Synthetic tip','desc'=>'Education','blog_ref'=>'Test source',
                'status'=>$status,'release_now'=>$release]);
        }
        $this->getJson('/api/blogs')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/blog_details/1')->assertOk()->assertJsonPath('data.title','Synthetic tip');
        foreach ([2,3,999] as $id) $this->getJson('/api/blog_details/'.$id)->assertNotFound();
    }

    public function test_offer_feed_excludes_expired_future_disabled_exhausted_and_invalid_dates(): void
    {
        $this->travelTo(now()->setDate(2026,11,9)->startOfDay());
        $base = ['name'=>'Synthetic offer','offer_code'=>'TESTONLY','discount'=>10,
            'discount_type'=>'percentage','desc'=>'Selected appointments only',
            'start_end_date'=>'2026-11-01 - 2026-11-30','min_discount'=>20,
            'user_id'=>'123','doctor_id'=>'456','max_use'=>10,'use_count'=>0,'status'=>1];
        Offer::create($base);
        foreach ([['start_end_date'=>'2026-10-01 - 2026-10-31'],
            ['start_end_date'=>'2026-12-01 - 2026-12-31'], ['status'=>0],
            ['use_count'=>10], ['start_end_date'=>'bad'],
            ['start_end_date'=>'2026-02-30 - 2026-11-30']] as $change) {
            Offer::create(array_merge($base,$change));
        }
        $response=$this->getJson('/api/offers')->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.desc','Selected appointments only')
            ->assertJsonPath('data.0.start_end_date','2026-11-01 - 2026-11-30');
        $this->assertArrayNotHasKey('user_id',$response->json('data.0'));
        $this->assertArrayNotHasKey('doctor_id',$response->json('data.0'));
        $this->travelBack();
    }

    public function test_admin_unpublishing_takes_effect_on_next_request(): void
    {
        $blog=Blog::create(['title'=>'Synthetic tip','desc'=>'Text','blog_ref'=>'Source',
            'status'=>1,'release_now'=>1]);
        $this->getJson('/api/blogs')->assertJsonCount(1,'data');
        $blog->update(['status'=>0]);
        $this->getJson('/api/blogs')->assertJsonCount(0,'data');
        $this->getJson('/api/blog_details/'.$blog->id)->assertNotFound();
    }
}
