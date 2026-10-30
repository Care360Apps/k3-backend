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
            $table->unsignedTinyInteger('offers_video_call')->default(1)->after('language');
            $table->unsignedTinyInteger('offers_home_visit')->default(1)->after('offers_video_call');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctor', function (Blueprint $table) {
            $table->dropColumn(['offers_video_call', 'offers_home_visit']);
        });
    }
};
