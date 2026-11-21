<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('facility_directory')) return;
        Schema::create('facility_directory', function (Blueprint $t) {
            $t->id();
            $t->string('type', 20);
            $t->string('name', 200);
            $t->string('state', 30);
            $t->string('lga', 100)->nullable();
            $t->text('address')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('source_name', 120);
            $t->string('source_id', 200)->nullable();
            $t->text('source_url')->nullable();
            $t->string('review_status', 24)->default('pending');
            $t->string('listing_status', 24)->default('draft');
            $t->string('partner_status', 24)->default('none');
            $t->unsignedBigInteger('linked_hospital_id')->nullable();
            $t->unsignedBigInteger('linked_pharmacy_id')->nullable();
            $t->timestamp('last_verified_at')->nullable();
            $t->timestamps();
            $t->index(['state', 'type', 'listing_status'], 'facility_directory_discovery');
            $t->unique(['source_name', 'source_id'], 'facility_directory_source_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('facility_directory'); }
};
