<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('course', function(Blueprint $t) {
            $t->string('provider', 30)->default('cnu');
            $t->boolean('is_published')->default(false)->index();
            $t->decimal('amount',10,2)->nullable()->default(null)->change();
        });
    }
    public function down(): void {
        // Retain nullable tuition: rollback must never convert unknown tuition to free.
        Schema::table('course', fn(Blueprint $t) => $t->dropColumn(['provider','is_published']));
    }
};
