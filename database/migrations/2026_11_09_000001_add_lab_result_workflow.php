<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('report',function(Blueprint $t){$t->string('workflow_status',24)->default('booked');$t->string('private_result_name',100)->nullable();$t->timestamp('result_released_at')->nullable();});
  Schema::create('lab_workflow_events',function(Blueprint $t){$t->id();$t->unsignedBigInteger('report_id');$t->unsignedBigInteger('actor_user_id');$t->string('from_status',24);$t->string('to_status',24);$t->timestamp('created_at');$t->index(['report_id','id']);});
 }
 public function down(): void {Schema::dropIfExists('lab_workflow_events');Schema::table('report',function(Blueprint $t){$t->dropColumn(['workflow_status','private_result_name','result_released_at']);});}
};
