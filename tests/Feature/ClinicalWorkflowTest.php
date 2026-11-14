<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class ClinicalWorkflowTest extends TestCase {
 private string $fixtureStorage;
 private string $originalStorage;
 protected function setUp(): void {
  parent::setUp();
  if(!app()->environment('testing')) $this->markTestSkipped('Synthetic tests only.');
  config()->set('database.default','sqlite');
  config()->set('database.connections.sqlite.database',':memory:');
  config()->set('app.key','base64:'.base64_encode(str_repeat('q',32)));
  DB::purge('sqlite');DB::setDefaultConnection('sqlite');
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');$t->boolean('status')->default(1);});
  Schema::create('roles',function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');$t->timestamps();});
  Schema::create('model_has_roles',function(Blueprint $t){$t->integer('role_id');$t->string('model_type');$t->integer('model_id');});
  Schema::create('doctor',function(Blueprint $t){$t->id();$t->integer('user_id');$t->string('user_role')->nullable();$t->boolean('is_approved');$t->boolean('status');});
  Schema::create('appointment',function(Blueprint $t){$t->id();$t->integer('doctor_id');$t->integer('user_id');$t->string('patient_name')->default('Synthetic Patient');$t->string('appointment_id')->nullable();$t->string('date')->nullable();$t->string('time')->nullable();$t->string('appointment_status')->default('approved');$t->integer('age')->default(32);});
  (require database_path('migrations/2026_11_04_000001_create_doctor_notes_table.php'))->up();
  DB::table('roles')->insert(['id'=>1,'name'=>'doctor','guard_name'=>'web']);
  foreach([101,102,103,104,105] as $id){
   DB::table('users')->insert(['id'=>$id,'name'=>'Synthetic account','email'=>$id.'@care360.example.test']);
   if($id!==104) DB::table('model_has_roles')->insert(['role_id'=>1,'model_type'=>User::class,'model_id'=>$id]);
  }
  foreach([[201,101,'doctor',1],[202,102,'doctor',1],[203,103,'provider',1],[205,105,null,1]] as [$id,$uid,$role,$approved])
   DB::table('doctor')->insert(['id'=>$id,'user_id'=>$uid,'user_role'=>$role,'is_approved'=>$approved,'status'=>1]);
  DB::table('appointment')->insert([['id'=>301,'doctor_id'=>201,'user_id'=>104],['id'=>302,'doctor_id'=>202,'user_id'=>104],['id'=>305,'doctor_id'=>205,'user_id'=>104]]);
  Schema::create('lab',function(Blueprint $t){$t->id();$t->integer('user_id');$t->string('name');$t->boolean('status');});
  Schema::create('report',function(Blueprint $t){$t->id();$t->integer('lab_id');$t->integer('user_id');$t->string('patient_name');$t->integer('age');$t->string('workflow_status');$t->string('private_result_name')->nullable();$t->timestamps();});
  Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');});
  (require database_path('migrations/2026_11_09_000002_create_clinical_workflow.php'))->up();
  (require database_path('migrations/2026_11_10_000001_expand_clinical_payload_capacity.php'))->up();
  DB::table('roles')->insert(['id'=>2,'name'=>'laboratory','guard_name'=>'web']);
  foreach([106,107] as $uid){DB::table('users')->insert(['id'=>$uid,'name'=>'Synthetic Lab','email'=>$uid.'@care360.example.test']);DB::table('model_has_roles')->insert(['role_id'=>2,'model_type'=>User::class,'model_id'=>$uid]);}
  DB::table('lab')->insert([['id'=>7,'user_id'=>106,'name'=>'Synthetic Lab A','status'=>1],['id'=>8,'user_id'=>107,'name'=>'Synthetic Lab B','status'=>1]]);
  DB::table('report')->insert(['id'=>90,'lab_id'=>7,'user_id'=>104,'patient_name'=>'Synthetic Patient','age'=>32,'workflow_status'=>'released']);
  \Illuminate\Support\Facades\Gate::define('test_report',fn(User $u)=>$u->hasRole('laboratory'));

  $this->app->make('auth')->guard('api');
  $this->originalStorage=$this->app->storagePath();
  $this->fixtureStorage=sys_get_temp_dir().DIRECTORY_SEPARATOR.'care360-clinical-fixture-'.bin2hex(random_bytes(8));
  $folder=$this->fixtureStorage.'/app/private/medical/lab-results';mkdir($folder,0700,true);
  $this->app->useStoragePath($this->fixtureStorage);
  file_put_contents($folder.'/'.str_repeat('c',32).'.pdf','%PDF-1.4 Synthetic test result only');
  DB::table('report')->where('id',90)->update(['private_result_name'=>str_repeat('c',32).'.pdf']);

 }
 protected function tearDown(): void {
  if(isset($this->fixtureStorage)){
   $this->app->useStoragePath($this->originalStorage);
   $file=$this->fixtureStorage.'/app/private/medical/lab-results/'.str_repeat('c',32).'.pdf';if(is_file($file))unlink($file);
   foreach(['/app/private/medical/lab-results','/app/private/medical','/app/private','/app',''] as $part)if(is_dir($this->fixtureStorage.$part))@rmdir($this->fixtureStorage.$part);
  }
  DB::disconnect('sqlite');parent::tearDown();
 }

 private function observation():array{return ['allergy_status'=>'reported','allergies'=>[['substance'=>'Synthetic substance','reaction'=>'Synthetic reaction','severity'=>'unknown']],'vitals'=>['temperature_c'=>37.2,'spo2_percent'=>98],'source'=>'patient_reported','observed_at'=>now()->subMinute()->toIso8601String()];}
 private function orderData(array $extra=[]):array{return array_replace(['client_request_id'=>'11111111-1111-4111-8111-111111111111','kind'=>'investigation','title'=>'Synthetic investigation','details'=>'Synthetic clinical indication','clinical_contact'=>'Synthetic clinic contact - TEST ONLY','urgency'=>'routine','lab_id'=>7],$extra);}
 private function createOrder():int {
  return $this->actingAs(User::findOrFail(101),'api')->postJson('/api/clinical/appointments/301/orders',$this->orderData())->assertCreated()->json('data.order.id');
 }
 private function link(int $id):void {
  $this->actingAs(User::findOrFail(106),'web')->post('/lab_clinical_requests/link',['order_id'=>$id,'report_id'=>90,'identity_checked'=>true],['Accept'=>'application/json'])->assertRedirect();
 }
 public function test_structured_observations_are_encrypted_append_only_and_patient_scoped():void {
  $this->actingAs(User::findOrFail(101),'api');
  $this->postJson('/api/clinical/appointments/301/observations',$this->observation()+['user_id'=>999])->assertCreated();
  $row=DB::table('clinical_observations')->first();$this->assertSame(104,$row->user_id);$this->assertStringNotContainsString('Synthetic substance',$row->payload);
  $this->getJson('/api/clinical/appointments/301')->assertOk()->assertJsonPath('data.observations.0.payload.allergies.0.substance','Synthetic substance');
  $this->postJson('/api/clinical/appointments/301/observations',array_replace($this->observation(),['allergy_status'=>'none_known','allergies'=>[]]))->assertCreated();
  $this->assertSame(2,DB::table('clinical_observations')->count());
  $this->getJson('/api/clinical/appointments/302')->assertNotFound();
 }
 public function test_allergy_status_and_percentages_cannot_be_ambiguous():void {
  $this->actingAs(User::findOrFail(101),'api');
  $this->postJson('/api/clinical/appointments/301/observations',array_replace($this->observation(),['allergy_status'=>'none_known']))->assertUnprocessable();
  $this->postJson('/api/clinical/appointments/301/observations',array_replace($this->observation(),['vitals'=>['spo2_percent'=>101]]))->assertUnprocessable();
  $this->postJson('/api/clinical/appointments/301/observations',array_replace($this->observation(),['observed_at'=>now()->addDay()->toIso8601String()]))->assertUnprocessable();
  $this->assertSame(0,DB::table('clinical_observations')->count());
 }
 public function test_provider_and_revoked_doctor_cannot_use_clinical_workspace():void {
  $this->actingAs(User::findOrFail(103),'api')->getJson('/api/clinical/appointments/301')->assertForbidden();
  DB::table('doctor')->where('id',201)->update(['is_approved'=>0]);
  $this->actingAs(User::findOrFail(101),'api')->getJson('/api/clinical/appointments/301')->assertForbidden();
 }
 public function test_request_retries_do_not_duplicate_and_changed_content_conflicts():void {
  $id=$this->createOrder();
  $this->postJson('/api/clinical/appointments/301/orders',$this->orderData())->assertOk()->assertJsonPath('data.order.id',$id);
  $this->postJson('/api/clinical/appointments/301/orders',$this->orderData(['title'=>'Different request']))->assertStatus(409);
  $this->assertSame(1,DB::table('clinical_orders')->count());$this->assertStringNotContainsString('Synthetic clinical indication',DB::table('clinical_orders')->value('payload'));
 }
 public function test_lab_link_checks_ownership_patient_identity_and_existing_booking():void {
  $id=$this->createOrder();
  $this->actingAs(User::findOrFail(107),'web')->post('/lab_clinical_requests/link',['order_id'=>$id,'report_id'=>90,'identity_checked'=>true],['Accept'=>'application/json'])->assertNotFound();
  DB::table('report')->where('id',90)->update(['patient_name'=>'Different family member']);
  $this->actingAs(User::findOrFail(106),'web')->post('/lab_clinical_requests/link',['order_id'=>$id,'report_id'=>90,'identity_checked'=>true],['Accept'=>'application/json'])->assertUnprocessable();
  $this->assertNull(DB::table('clinical_orders')->value('report_id'));
 }
 public function test_critical_flag_acknowledgement_is_bound_to_doctor_and_retains_audit():void {
  $id=$this->createOrder();$this->link($id);
  $this->post('/lab_clinical_requests/critical',['order_id'=>$id,'reason'=>'Synthetic critical concern','contact_attempt'=>'Synthetic direct contact attempt'],['Accept'=>'application/json'])->assertRedirect();
  $this->post('/lab_clinical_requests/contact',['order_id'=>$id,'note'=>'Synthetic second contact attempt'],['Accept'=>'application/json'])->assertRedirect();
  $this->assertStringNotContainsString('Synthetic second contact attempt',DB::table('clinical_workflow_events')->where('action','lab_contact_attempt')->value('details'));
  $flag=DB::table('clinical_critical_results')->first();$this->assertStringNotContainsString('Synthetic critical concern',$flag->reason);
  $this->actingAs(User::findOrFail(102),'api')->postJson('/api/clinical/critical-results/'.$flag->id.'/acknowledge',['reviewed_result'=>true,'note'=>'Foreign doctor'])->assertNotFound();
  $this->actingAs(User::findOrFail(101),'api')->getJson('/api/clinical/critical-results')->assertOk()->assertJsonCount(1,'data.critical_results');
  $this->postJson('/api/clinical/orders/'.$id.'/status',['status'=>'reviewed','note'=>'Skip acknowledgement'])->assertStatus(409);
  $this->postJson('/api/clinical/critical-results/'.$flag->id.'/acknowledge',['reviewed_result'=>false,'note'=>'No review attestation'])->assertUnprocessable();
  $this->postJson('/api/clinical/critical-results/'.$flag->id.'/acknowledge',['reviewed_result'=>true,'note'=>'Synthetic reviewed result and follow-up recorded'])->assertOk();
  $this->postJson('/api/clinical/critical-results/'.$flag->id.'/acknowledge',['reviewed_result'=>true,'note'=>'Overwrite'])->assertStatus(409);
  $this->getJson('/api/clinical/critical-results')->assertOk()->assertJsonCount(0,'data.critical_results');
  $this->postJson('/api/clinical/orders/'.$id.'/status',['status'=>'reviewed','note'=>'Synthetic result review complete'])->assertOk();
  $this->assertSame(1,DB::table('clinical_workflow_events')->where('action','critical_acknowledged')->count());
 }
 public function test_referral_completion_needs_owning_doctor_and_a_recorded_outcome():void {
  $this->actingAs(User::findOrFail(101),'api');
  $id=$this->postJson('/api/clinical/appointments/301/orders',$this->orderData(['kind'=>'referral','target'=>'Synthetic receiving service']))->assertCreated()->json('data.order.id');
  $this->actingAs(User::findOrFail(102),'api')->postJson('/api/clinical/orders/'.$id.'/status',['status'=>'completed','note'=>'Foreign'])->assertNotFound();
  $this->actingAs(User::findOrFail(101),'api')->postJson('/api/clinical/orders/'.$id.'/status',['status'=>'completed','note'=>'Synthetic referral handover outcome recorded'])->assertOk();
  $this->assertSame('completed',DB::table('clinical_orders')->where('id',$id)->value('status'));
 }

 public function test_critical_flags_require_released_result_and_assigned_laboratory():void {
  $id=$this->createOrder();$this->link($id);
  $this->actingAs(User::findOrFail(107),'web')->post('/lab_clinical_requests/critical',['order_id'=>$id,'reason'=>'Foreign flag','contact_attempt'=>'Foreign attempt'],['Accept'=>'application/json'])->assertNotFound();
  DB::table('report')->where('id',90)->update(['workflow_status'=>'processing']);
  $this->actingAs(User::findOrFail(106),'web')->post('/lab_clinical_requests/critical',['order_id'=>$id,'reason'=>'Premature flag','contact_attempt'=>'Synthetic contact attempt'],['Accept'=>'application/json'])->assertStatus(409);
  $this->assertSame(0,DB::table('clinical_critical_results')->count());
 }
 public function test_result_download_is_limited_to_the_ordering_doctor_after_release():void {
  $id=$this->createOrder();$this->link($id);
  $this->actingAs(User::findOrFail(102),'api')->get('/api/clinical/orders/'.$id.'/result')->assertNotFound();
  $this->actingAs(User::findOrFail(101),'api')->get('/api/clinical/orders/'.$id.'/result')->assertOk()->assertDownload('care360-lab-result.pdf');
  $this->assertSame(1,DB::table('clinical_workflow_events')->where('action','result_accessed')->count());
  DB::table('report')->where('id',90)->update(['workflow_status'=>'processing']);
  $this->get('/api/clinical/orders/'.$id.'/result')->assertNotFound();
 }
 public function test_older_observation_records_are_paginated_without_losing_history():void {
  for($i=0;$i<21;$i++)\App\Models\ClinicalObservation::create(['appointment_id'=>301,'doctor_id'=>201,'user_id'=>104,'actor_user_id'=>101,'payload'=>['allergy_status'=>'unknown','allergies'=>[],'vitals'=>[],'source'=>'measured'],'observed_at'=>now()->subMinute()]);
  $this->actingAs(User::findOrFail(101),'api')->getJson('/api/clinical/appointments/301?page=1')->assertOk()->assertJsonCount(20,'data.observations')->assertJsonPath('data.meta.last_page',2);
  $this->getJson('/api/clinical/appointments/301?page=2')->assertOk()->assertJsonCount(1,'data.observations');
 }

 public function test_observation_offsets_are_normalised_to_utc_and_timezone_is_required():void {
  $time=now()->subHour();$body=array_replace($this->observation(),['observed_at'=>$time->copy()->setTimezone('Africa/Lagos')->toIso8601String()]);
  $this->actingAs(User::findOrFail(101),'api')->postJson('/api/clinical/appointments/301/observations',$body)->assertCreated();
  $this->assertSame($time->utc()->format('Y-m-d H:i:s'),DB::table('clinical_observations')->value('observed_at'));
  $body['observed_at']='2026-11-01 12:00:00';$this->postJson('/api/clinical/appointments/301/observations',$body)->assertUnprocessable();
 }

 public function test_laboratory_request_page_renders_escaped_data_and_excludes_other_labs():void {
  $this->actingAs(User::findOrFail(101),'api')->postJson('/api/clinical/appointments/301/orders',$this->orderData(['title'=>'<script>Synthetic unsafe markup</script>']))->assertCreated();
  $this->postJson('/api/clinical/appointments/301/orders',$this->orderData(['client_request_id'=>'22222222-2222-4222-8222-222222222222','lab_id'=>8,'title'=>'Other laboratory request']))->assertCreated();
  $views=$this->fixtureStorage.'/views';$compiled=$this->fixtureStorage.'/compiled';mkdir($views.'/layout',0700,true);mkdir($compiled,0700,true);
  file_put_contents($views.'/layout/mainlayout_admin.blade.php','@yield("content")');
  config(['view.compiled'=>$compiled]);$this->app['view']->getFinder()->prependLocation($views);
  try {
   $response=$this->actingAs(User::findOrFail(106),'web')->get('/lab_clinical_requests')->assertOk();
   $response->assertSee('&lt;script&gt;Synthetic unsafe markup&lt;/script&gt;',false)->assertDontSee('<script>Synthetic unsafe markup</script>',false)->assertDontSee('Other laboratory request');
  } finally {
   foreach(glob($compiled.'/*.php') as $file)if(str_starts_with((string)realpath($file),(string)realpath($this->fixtureStorage).DIRECTORY_SEPARATOR))unlink($file);
   unlink($views.'/layout/mainlayout_admin.blade.php');@rmdir($views.'/layout');@rmdir($views);@rmdir($compiled);
  }
 }

 public function test_clinical_register_is_doctor_scoped_and_returns_only_encounter_summaries():void {
  $this->actingAs(User::findOrFail(101),'api')->getJson('/api/clinical/encounters')->assertOk()->assertJsonCount(1,'data.encounters')->assertJsonPath('data.encounters.0.id',301)->assertJsonMissingPath('data.encounters.0.user_id');
  $this->getJson('/api/clinical/encounters?q=not-present')->assertOk()->assertJsonCount(0,'data.encounters');
  $this->actingAs(User::findOrFail(103),'api')->getJson('/api/clinical/encounters')->assertForbidden();
 }

 public function test_valid_multibyte_allergy_payload_round_trips_above_text_column_capacity():void {
  $allergy=['substance'=>str_repeat("\u{1F9EA}",160),'reaction'=>str_repeat("\u{1F9EA}",500),'severity'=>'unknown'];
  $body=array_replace($this->observation(),['allergies'=>array_fill(0,30,$allergy)]);
  $this->actingAs(User::findOrFail(101),'api')->postJson('/api/clinical/appointments/301/observations',$body)->assertCreated();
  $this->assertGreaterThan(65535,strlen(DB::table('clinical_observations')->value('payload')));
  $this->getJson('/api/clinical/appointments/301')->assertOk()->assertJsonCount(30,'data.observations.0.payload.allergies')->assertJsonPath('data.observations.0.payload.allergies.0.reaction',$allergy['reaction']);
 }
}
