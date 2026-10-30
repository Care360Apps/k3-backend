<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lab_gallery')) {
            Schema::create('lab_gallery', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lab_id');
                $table->string('image');
                $table->timestamps();
                $table->foreign('lab_id')->references('id')->on('lab')->onDelete('cascade')->onUpdate('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_gallery');
    }
};
