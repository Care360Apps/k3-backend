<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DoctorWorkspaceTest extends TestCase
{
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
  Schema::create('appointment',function(Blueprint $t){$t->id();$t->integer('doctor_id');$t->integer('user_id');});
  (require database_path('migrations/2026_11_04_000001_create_doctor_notes_table.php'))->up();
  DB::table('roles')->insert(['id'=>1,'name'=>'doctor','guard_name'=>'web']);
  foreach([101,102,103,104,105] as $id){
   DB::table('users')->insert(['id'=>$id,'name'=>'Synthetic account','email'=>$id.'@care360.example.test']);
   if($id!==104) DB::table('model_has_roles')->insert(['role_id'=>1,'model_type'=>User::class,'model_id'=>$id]);
  }
  foreach([[201,101,'doctor',1],[202,102,'doctor',1],[203,103,'provider',1],[205,105,null,1]] as [$id,$uid,$role,$approved])
   DB::table('doctor')->insert(['id'=>$id,'user_id'=>$uid,'user_role'=>$role,'is_approved'=>$approved,'status'=>1]);
  DB::table('appointment')->insert([['id'=>301,'doctor_id'=>201,'user_id'=>104],['id'=>302,'doctor_id'=>202,'user_id'=>104],['id'=>305,'doctor_id'=>205,'user_id'=>104]]);
 }
 protected function tearDown(): void {DB::disconnect('sqlite');parent::tearDown();}
 public function test_doctor_can_save_read_and_finalize_own_private_note(): void {
  $this->actingAs(User::findOrFail(101),'api');
  $result=$this->postJson('/api/doctor_notes',['appointment_id'=>301,'title'=>'Synthetic note','body'=>'Private synthetic body','status'=>'draft'])->assertCreated();
  $id=$result->json('data.0.id');
  $this->assertStringNotContainsString('Private synthetic body',DB::table('doctor_notes')->where('id',$id)->value('body'));
  $this->putJson('/api/doctor_notes/'.$id,['status'=>'final'])->assertOk();
  $this->getJson('/api/doctor_notes?appointment_id=301')->assertOk()->assertJsonPath('data.0.body','Private synthetic body');
  $this->putJson('/api/doctor_notes/'.$id,['body'=>'Overwrite'])->assertStatus(409);
 }
 public function test_foreign_appointments_and_provider_roles_cannot_access_notes(): void {
  $this->actingAs(User::findOrFail(101),'api')->getJson('/api/doctor_notes?appointment_id=302')->assertNotFound();
  $this->actingAs(User::findOrFail(103),'api')->getJson('/api/doctor_notes?appointment_id=301')->assertForbidden();
  $this->actingAs(User::findOrFail(104),'api')->getJson('/api/doctor_notes?appointment_id=301')->assertForbidden();
 }
 public function test_approved_legacy_doctor_retains_clinical_note_access(): void {
  $this->actingAs(User::findOrFail(105),'api')->postJson('/api/doctor_notes',[
   'appointment_id'=>305,'title'=>'Legacy doctor note','body'=>'Synthetic body'])->assertCreated();
 }

 public function test_history_retains_assigned_appointments_when_specialty_is_missing(): void {
  Schema::table('users',function(Blueprint $t){$t->string('image')->nullable();});
  Schema::table('doctor',function(Blueprint $t){$t->string('name')->default('Synthetic clinician');$t->integer('treatment_id')->nullable();});
  Schema::table('appointment',function(Blueprint $t){
   $t->string('date')->default('2026-01-01');$t->string('time')->default('10:00 am');
   $t->string('end_time')->nullable();$t->integer('duration_minutes')->nullable();
   $t->integer('hospital_id')->nullable();$t->integer('patient_address')->nullable();
   $t->string('patient_name')->default('Synthetic patient');$t->string('appointment_status')->default('approved');
   $t->string('visit_type')->default('video_call');$t->string('cancel_reason')->nullable();$t->timestamps();
  });
  Schema::create('settings',function(Blueprint $t){$t->id();$t->integer('auto_cancel')->default(30);});
  Schema::create('treatments',function(Blueprint $t){$t->id();$t->string('name');});
  Schema::create('hospital',function(Blueprint $t){$t->id();$t->string('name');$t->string('address');});
  Schema::create('review',function(Blueprint $t){$t->id();$t->integer('appointment_id');$t->float('rate');});
  DB::table('settings')->insert(['id'=>1,'auto_cancel'=>30]);
  DB::connection()->getPdo()->sqliteCreateFunction('STR_TO_DATE',fn($value,$format)=>$value,2);
  $this->actingAs(User::findOrFail(101),'api')->getJson('/api/appointment_history')
   ->assertOk()->assertJsonPath('success',true)->assertJsonPath('data.past_appointment.0.id',301)
   ->assertJsonPath('data.past_appointment.0.treatment','')->assertJsonCount(1,'data.past_appointment');
 }
}
