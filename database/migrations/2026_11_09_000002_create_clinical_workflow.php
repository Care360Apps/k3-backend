<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('clinical_observations',function(Blueprint $t){$t->id();$t->unsignedBigInteger('appointment_id');$t->unsignedBigInteger('doctor_id');$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('actor_user_id');$t->text('payload');$t->timestamp('observed_at');$t->timestamps();$t->index(['doctor_id','appointment_id'],'clinical_obs_owner');});
  Schema::create('clinical_orders',function(Blueprint $t){$t->id();$t->uuid('client_request_id');$t->char('request_fingerprint',64);$t->unique(['doctor_id','client_request_id'],'clinical_order_request_key');$t->unsignedBigInteger('appointment_id');$t->unsignedBigInteger('doctor_id');$t->unsignedBigInteger('user_id');$t->string('kind',20);$t->string('urgency',20);$t->string('status',24)->default('requested');$t->unsignedBigInteger('lab_id')->nullable();$t->unsignedBigInteger('report_id')->nullable()->unique();$t->text('payload');$t->timestamps();$t->index(['doctor_id','appointment_id'],'clinical_order_owner');$t->index(['lab_id','status'],'clinical_order_lab');});
  Schema::create('clinical_critical_results',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id')->unique();$t->unsignedBigInteger('report_id');$t->unsignedBigInteger('flagged_by');$t->text('reason');$t->text('contact_attempt');$t->unsignedBigInteger('acknowledged_by')->nullable();$t->timestamp('acknowledged_at')->nullable();$t->text('acknowledgement')->nullable();$t->timestamps();$t->index(['report_id','acknowledged_at'],'critical_result_pending');});
  Schema::create('clinical_workflow_events',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id')->nullable();$t->unsignedBigInteger('actor_user_id');$t->string('action',40);$t->text('details')->nullable();$t->timestamp('created_at');$t->index(['order_id','id'],'clinical_event_order');});
 }
 public function down():void {foreach(['clinical_workflow_events','clinical_critical_results','clinical_orders','clinical_observations'] as $table)Schema::dropIfExists($table);}
};
