<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('doctor_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('appointment_id')->index();
            $table->text('title'); // Encrypted by model casting.
            $table->longText('body'); // Encrypted by model casting.
            $table->string('status', 16)->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['doctor_id', 'appointment_id', 'created_at'], 'doctor_notes_owner_appointment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_notes');
    }
};
