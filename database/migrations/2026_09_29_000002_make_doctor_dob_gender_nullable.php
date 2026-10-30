<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Providers register without a date of birth or gender.
     */
    public function up(): void
    {
        Schema::table('doctor', function (Blueprint $table) {
            $table->string('dob')->nullable()->change();
            $table->string('gender')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('doctor', function (Blueprint $table) {
            $table->string('dob')->nullable(false)->change();
            $table->string('gender')->nullable(false)->change();
        });
    }
};
