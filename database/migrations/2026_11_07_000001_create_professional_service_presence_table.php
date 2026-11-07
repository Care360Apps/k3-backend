<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Independent, strictly opt-in state: old verified professionals stay
        // OFFLINE until they explicitly publish a service point and a shift.
        Schema::create('professional_service_presence', function (Blueprint $table) {
            $table->id();
            // Legacy vendor database was imported from SQL, not created by
            // Laravel migrations. Match its doctor ID safely and add an
            // actual FK only after verifying the live column type in staging.
            // Existing Care360 doctor_documents and doctor_notes similarly
            // use an indexed unsignedBigInteger rather than an assumed FK.
            $table->unsignedBigInteger('doctor_id')->unique();
            $table->boolean('accepting_requests')->default(false);
            $table->decimal('public_lat', 9, 6)->nullable();
            $table->decimal('public_lng', 9, 6)->nullable();
            $table->unsignedTinyInteger('service_radius_km')->default(10);
            $table->timestamp('public_location_consent_at')->nullable();
            $table->timestamp('on_duty_until')->nullable();
            $table->timestamps();
            $table->index(['accepting_requests', 'on_duty_until'], 'presence_on_duty_idx');
            $table->index(['public_lat', 'public_lng'], 'presence_public_area_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_service_presence');
    }
};
