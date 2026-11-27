<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_report_migration_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id')->index();
            $table->string('legacy_filename', 255);
            $table->string('private_filename', 64)->unique();
            $table->char('sha256', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Preserve migration evidence; rollback must be explicitly planned.
        throw new \RuntimeException('Report migration ledger requires manual retention review.');
    }
};
