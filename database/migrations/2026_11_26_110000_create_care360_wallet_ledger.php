<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('care360_wallet_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_key', 120)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('kind', 32);
            $table->char('currency', 3);
            $table->timestamps();
        });
        Schema::create('care360_wallet_journals', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->string('kind', 32);
            $table->char('currency', 3);
            $table->char('request_hash', 64);
            $table->timestamps();
        });
        Schema::create('care360_wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('care360_wallet_journals')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('care360_wallet_accounts')->restrictOnDelete();
            $table->bigInteger('signed_minor');
            $table->timestamp('created_at');
            $table->unique(['journal_id', 'account_id'], 'c360_wallet_journal_account_unique');
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Wallet ledger rollback requires an approved financial data retention procedure.');
    }
};
