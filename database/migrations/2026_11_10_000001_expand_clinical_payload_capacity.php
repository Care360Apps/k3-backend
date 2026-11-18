<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  // Encrypted JSON can exceed TEXT capacity after Unicode escaping and Base64.
  foreach(['clinical_observations','clinical_orders'] as $name)Schema::table($name,function(Blueprint $t){$t->longText('payload')->change();});
 }
 public function down():void {
  // Keep the widened type on rollback; narrowing may destroy retained clinical data.
 }
};
