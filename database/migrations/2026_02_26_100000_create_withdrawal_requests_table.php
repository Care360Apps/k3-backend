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
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->string('holder_type', 32); // doctor, provider, pharmacy, lab
            $table->unsignedBigInteger('holder_id');
            $table->decimal('amount', 12, 2);
            $table->string('status', 24)->default('pending'); // pending, approved, rejected, paid
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->index(['holder_type', 'holder_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
