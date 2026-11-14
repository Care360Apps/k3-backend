<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\LabWorkflowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
class LabWorkflowTest extends TestCase {
    use \Tests\Support\SeedsWebLocalization;

 protected function setUp():void {
  parent::setUp();config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','app.key'=>'base64:'.base64_encode(str_repeat('l',32))]);DB::purge('sqlite');
  $this->seedWebLocalization();
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');});
  Schema::create('report',function(Blueprint $t){$t->id();$t->string('report_id');$t->integer('lab_id');$t->integer('user_id');$t->string('date');$t->timestamps();});
  (require database_path('migrations/2026_11_09_000001_add_lab_result_workflow.php'))->up();
  DB::table('users')->insert([['id'=>1,'name'=>'Synthetic Patient','email'=>'labpatient@example.test'],['id'=>2,'name'=>'Other Synthetic Patient','email'=>'other@example.test']]);
  DB::table('report')->insert(['id'=>10,'report_id'=>'SYNTHETIC-LAB-10','lab_id'=>7,'user_id'=>1,'date'=>'2026-11-09']);
 }
 public function test_sample_to_release_workflow_has_audited_transitions():void {
  $s=new LabWorkflowService();$s->advance(10,55,'sample_collected',7);$s->advance(10,55,'processing',7);
  DB::table('report')->where('id',10)->update(['private_result_name'=>str_repeat('a',32).'.pdf']);
  $s->advance(10,55,'result_ready',7);$s->advance(10,55,'released',7);
  $this->assertSame('released',DB::table('report')->where('id',10)->value('workflow_status'));
  $this->assertNotNull(DB::table('report')->where('id',10)->value('result_released_at'));
  $this->assertSame(4,DB::table('lab_workflow_events')->where('report_id',10)->count());
  try{$s->advance(10,55,'processing',7);$this->fail('Released result changed');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
 }
 public function test_cross_lab_and_skipped_release_are_rejected():void {
  $s=new LabWorkflowService();try{$s->advance(10,55,'processing',8);$this->fail('Cross-lab update');}catch(\Illuminate\Database\Eloquent\ModelNotFoundException $e){$this->assertSame('booked',DB::table('report')->value('workflow_status'));}
  try{$s->advance(10,55,'released',7);$this->fail('Skipped review');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
 }
 public function test_result_cannot_be_ready_without_file():void {
  $s=new LabWorkflowService();$s->advance(10,55,'processing',7);
  try{$s->advance(10,55,'result_ready',7);$this->fail('Missing result accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
 }
 public function test_patient_sees_only_own_status_and_cannot_download_unreleased_result():void {
  $this->actingAs(User::findOrFail(1),'api')->getJson('/api/patient/lab-results')->assertOk()->assertJsonCount(1,'data.data')->assertJsonPath('data.data.0.workflow_status','booked');
  $this->getJson('/api/patient/lab-results/10/file')->assertNotFound();
  $this->actingAs(User::findOrFail(2),'api')->getJson('/api/patient/lab-results')->assertOk()->assertJsonCount(0,'data.data');
  $this->getJson('/api/patient/lab-results/10/file')->assertNotFound();
 }
 public function test_private_file_names_reject_traversal_and_public_fallback():void {
  DB::table('report')->where('id',10)->update(['workflow_status'=>'released','private_result_name'=>'../../public/report.pdf']);
  $this->actingAs(User::findOrFail(1),'api')->getJson('/api/patient/lab-results/10/file')->assertNotFound();
 }

 private function laboratorySession(): void {
  Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');});
  Schema::create('lab',function(Blueprint $t){$t->id();$t->integer('user_id');});
  DB::table('lab')->insert(['id'=>7,'user_id'=>55]);
  \Illuminate\Support\Facades\Gate::define('test_report',fn()=>true);
  $user=new class extends User {
   public function hasRole($roles, ?string $guard=null):bool{return $roles==='laboratory';}
  };$user->id=55;$this->actingAs($user);
 }
 public function test_upload_rejects_missing_or_other_laboratory_booking():void {
  $this->laboratorySession();
  DB::table('report')->where('id',10)->update(['lab_id'=>8]);
  $file=\Illuminate\Http\UploadedFile::fake()->createWithContent('synthetic.pdf','%PDF-1.4 synthetic laboratory fixture');
  $this->post('/upload_report',['report_id'=>10,'upload_report'=>$file],['Accept'=>'application/json'])->assertNotFound();
  $this->assertNull(DB::table('report')->where('id',10)->value('private_result_name'));
 }
 public function test_new_upload_is_private_and_owning_patient_receives_only_released_file():void {
  $this->laboratorySession();
  $this->app->make('auth')->guard('api'); // Resolve the existing synthetic Passport fixture before using temporary storage.
  $originalStorage=$this->app->storagePath();
  $folder=sys_get_temp_dir().DIRECTORY_SEPARATOR.'care360-synthetic-lab-'.bin2hex(random_bytes(8));
  $this->app->useStoragePath($folder);
  try {
   $file=\Illuminate\Http\UploadedFile::fake()->createWithContent('synthetic.pdf','%PDF-1.4 synthetic laboratory fixture');
   $this->post('/upload_report',['report_id'=>10,'upload_report'=>$file],['Accept'=>'application/json'])->assertRedirect();
   $name=DB::table('report')->where('id',10)->value('private_result_name');
   $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\\.pdf$/',$name);
   $full=$folder.'/app/private/medical/lab-results/'.$name;$this->assertFileExists($full);
   $this->actingAs(User::findOrFail(1),'api')->getJson('/api/patient/lab-results/10/file')->assertNotFound();
   DB::table('report')->where('id',10)->update(['workflow_status'=>'released']);
   $this->get('/api/patient/lab-results/10/file')->assertOk()->assertDownload('care360-lab-result.pdf');
   $this->actingAs(User::findOrFail(2),'api')->getJson('/api/patient/lab-results/10/file')->assertNotFound();
  } finally {
   $this->app->useStoragePath($originalStorage);
   if(isset($full)&&is_file($full))unlink($full);
   foreach(['/app/private/medical/lab-results','/app/private/medical','/app/private','/app',''] as $part){if(is_dir($folder.$part))@rmdir($folder.$part);}
  }
 }
}
