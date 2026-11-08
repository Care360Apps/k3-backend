<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_prescription_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prescription_id')->unique();
            $table->string('legacy_filename', 120);
            $table->string('private_filename', 120);
            $table->char('sha256', 64);
            $table->string('state', 20)->default('staged');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // The ledger is required to restore original references. Do not
        // silently destroy the only rollback map while records are staged.
        if (Schema::hasTable('medical_prescription_migrations')
            && DB::table('medical_prescription_migrations')->exists()) {
            throw new RuntimeException('Medical-file rollback ledger is not empty; retain it.');
        }
        Schema::dropIfExists('medical_prescription_migrations');
    }
};
