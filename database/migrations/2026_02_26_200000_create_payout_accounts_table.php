<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('holder_type'); // doctor, provider, pharmacy, lab
            $table->unsignedBigInteger('holder_id');
            $table->string('account_name');
            $table->string('account_number');
            $table->string('bank_name');
            $table->timestamps();

            $table->index(['holder_type', 'holder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_accounts');
    }
};

