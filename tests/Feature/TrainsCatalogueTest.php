<?php
namespace Tests\Feature;
use App\Models\{Course,User};
use App\Http\Controllers\SuperAdmin\CourseController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\{Request,UploadedFile};
use Illuminate\Support\Facades\{DB,Gate,Schema};
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Tests\TestCase;
class TrainsCatalogueTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('t',32))]);
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array']);
        DB::purge('sqlite'); DB::setDefaultConnection('sqlite');
        (require database_path('migrations/2026_02_22_000001_create_course_table.php'))->up();
        (require database_path('migrations/2026_11_24_150000_add_trains_catalogue_controls.php'))->up();
    }
    private function course(array $extra=[]): Course {
        return Course::create(array_merge(['title'=>'Synthetic course','summary'=>'Summary','details'=>'Details','duration'=>'Two weeks','provider'=>'cnu','amount'=>null,'link'=>'https://www.cnumedical.com/apply','is_published'=>false],$extra));
    }
    private function input(array $extra=[]): array {
        return array_merge(['title'=>'Edited course','summary'=>'Summary','details'=>'Details','duration'=>'Two weeks','provider'=>'cnu','amount'=>'','link'=>'https://www.cnumedical.com/apply','is_published'=>'1'],$extra);
    }
    public function test_anonymous_catalogue_requests_are_rejected(): void {
        $this->getJson('/api/courses')->assertUnauthorized();
    }
    public function test_authenticated_roles_share_only_the_published_catalogue_and_updates(): void {
        $draft=$this->course(); $live=$this->course(['title'=>'Live','is_published'=>true,'amount'=>12500]);
        $deleted=$this->course(['is_published'=>true]); $deleted->delete();
        foreach(['patient','doctor','provider','pharmacy','lab'] as $role) {
            Passport::actingAs(new User(['name'=>'Synthetic','user_role'=>$role]));
            $this->getJson('/api/courses')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.title','Live');
        }
        Gate::shouldReceive('denies')->with('course_edit')->andReturn(false);
        (new CourseController)->update(Request::create('/course/'.$live->id,'PUT',$this->input()),$live->id);
        $this->getJson('/api/courses')->assertOk()->assertJsonPath('data.0.title','Edited course')->assertJsonPath('data.0.amount',null);
        (new CourseController)->update(Request::create('/course/'.$live->id,'PUT',$this->input(['is_published'=>'0'])),$live->id);
        $this->getJson('/api/courses')->assertOk()->assertJsonCount(0,'data');
        $this->assertFalse($draft->fresh()->is_published);
    }
    public function test_speakout_tuition_is_always_zero_and_application_remains_on_speakout(): void {
        Gate::shouldReceive('denies')->with('course_edit')->andReturn(false);
        $c=$this->course();
        (new CourseController)->update(Request::create('/course/'.$c->id,'PUT',$this->input(['provider'=>'speakout','amount'=>'900','link'=>'https://www.speakoutmentalhealth.org/courses'])),$c->id);
        $this->assertSame('0.00',$c->fresh()->amount);
        $this->assertSame('speakout',$c->fresh()->provider);
    }
    public function test_invalid_links_and_cnu_zero_tuition_are_rejected_without_changing_data(): void {
        Gate::shouldReceive('denies')->with('course_edit')->andReturn(false);
        $c=$this->course();
        foreach([['amount'=>0],['link'=>'javascript:alert(1)'],['link'=>'https://localhost/'],['link'=>'http://example.org/'],['provider'=>'speakout','link'=>'https://speakoutmentalhealth.org.evil.com/'],['amount'=>-1],['amount'=>'NaN']] as $bad) {
            try { (new CourseController)->update(Request::create('/course/'.$c->id,'PUT',$this->input($bad)),$c->id); $this->fail('Invalid course accepted'); }
            catch(ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        $this->assertSame('Synthetic course',$c->fresh()->title);
    }
    public function test_only_validated_fields_are_written_and_html_is_plain_text(): void {
        Gate::shouldReceive('denies')->with('course_edit')->andReturn(false);
        $c=$this->course();
        (new CourseController)->update(Request::create('/course/'.$c->id,'PUT',$this->input(['title'=>'<b>Visible</b>','id'=>999,'deleted_at'=>now()->toDateTimeString()])),$c->id);
        $this->assertSame('Visible',$c->fresh()->title); $this->assertFalse($c->fresh()->trashed());
        $this->assertSame($c->id,Course::first()->id);
    }
    public function test_course_management_requires_existing_admin_permission(): void {
        Gate::shouldReceive('denies')->with('course_edit')->andReturn(true);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new CourseController)->update(new Request,1);
    }
    public function test_admin_upload_publishes_an_image_without_seeding_content(): void {
        Gate::shouldReceive('denies')->with('course_add')->andReturn(false);
        $request=Request::create('/course','POST',$this->input());
        $request->files->set('image', UploadedFile::fake()->image('synthetic.jpg',16,16));
        (new CourseController)->store($request);
        $c=Course::firstOrFail(); $path=public_path('images/upload/'.$c->image);
        try {
            $this->assertFileExists($path);
            $this->get('/api/course-images/'.$c->image)->assertOk()->assertHeader('Content-Type','image/jpeg');
            $c->update(['is_published'=>false]);
            $this->get('/api/course-images/'.$c->image)->assertNotFound();
            $this->assertStringStartsWith('https://',$c->fullImage);
        } finally { @unlink($path); }
    }
    public function test_non_images_cannot_be_uploaded(): void {
        Gate::shouldReceive('denies')->with('course_add')->andReturn(false);
        $request=Request::create('/course','POST',$this->input());
        $request->files->set('image',UploadedFile::fake()->createWithContent('bad.php','<?php echo 1;'));
        $this->expectException(ValidationException::class);
        (new CourseController)->store($request);
    }
}
