<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('doctor', function (Blueprint $table) {
            // Average rating value for doctor (e.g. 4.7). Nullable to avoid breaking existing rows.
            $table->decimal('rate', 3, 2)->nullable()->after('lng');
            // Total number of reviews for doctor.
            $table->unsignedInteger('review')->nullable()->after('rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctor', function (Blueprint $table) {
            $table->dropColumn(['rate', 'review']);
        });
    }
};

