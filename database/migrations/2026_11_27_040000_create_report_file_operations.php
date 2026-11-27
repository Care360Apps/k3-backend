<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_report_file_operations', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('filename', 255);
            $table->char('sha256', 64);
            $table->string('state', 30)->default('prepared');
            $table->timestamp('http_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'filename']);
        });
        Schema::create('medical_report_release_events', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->unsignedBigInteger('operation_id')->nullable()->index();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Medical recovery evidence is retained; automatic rollback is prohibited.');
    }
};
