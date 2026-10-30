<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course')) {
            Schema::create('course', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('summary');
                $table->longText('details');
                $table->string('image')->nullable();
                $table->string('duration')->nullable();
                $table->decimal('amount', 10, 2)->default(0);
                $table->string('link')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('course')) {
            Schema::dropIfExists('course');
        }
    }
};
