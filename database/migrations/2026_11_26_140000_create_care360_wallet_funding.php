<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('care360_wallet_funding', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 100)->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24);
            $table->unsignedBigInteger('journal_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Wallet funding records require an approved retention procedure.');
    }
};
