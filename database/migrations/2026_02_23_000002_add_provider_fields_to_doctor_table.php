<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('doctor', function (Blueprint $table) {
            $table->string('user_role')->default('doctor')->after('user_id');
            $table->unsignedBigInteger('provider_type_id')->nullable()->after('user_role');
            $table->json('service_catalog')->nullable()->after('offers_home_visit');

            $table->foreign('provider_type_id')
                ->references('id')
                ->on('provider_types')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('doctor', function (Blueprint $table) {
            $table->dropForeign(['provider_type_id']);
            $table->dropColumn(['user_role', 'provider_type_id', 'service_catalog']);
        });
    }
};

