<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipt_claims', function (Blueprint $table) {
            $table->id();
            // Receipt hashes are unique across ALL product types and gateways.
            // This also avoids persisting raw payment tokens in the ledger.
            $table->char('receipt_sha256', 64)->unique();
            $table->string('gateway', 24);
            $table->unsignedBigInteger('user_id');
            $table->string('product_type', 32);
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
            $table->index(['product_type', 'product_id']);
        });
    }

    public function down(): void
    {
        // An active receipt ledger must never be accidentally erased by
        // rolling back an unrelated migration.
        if (Schema::hasTable('payment_receipt_claims')
            && DB::table('payment_receipt_claims')->exists()) {
            throw new RuntimeException('Payment receipt ledger contains claims. Retain it.');
        }
        Schema::dropIfExists('payment_receipt_claims');
    }
};
