<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('doctor', function (Blueprint $table) {
            $table->boolean('is_approved')->default(0)->after('status');
        });

        // Auto-approve existing active doctors so current behavior is preserved.
        DB::table('doctor')
            ->where('status', 1)
            ->update(['is_approved' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctor', function (Blueprint $table) {
            $table->dropColumn('is_approved');
        });
    }
};

