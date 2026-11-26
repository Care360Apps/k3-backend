<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (['paystack', 'checkout'] as $kind) {
            DB::table('care360_wallet_accounts')->insert([
                'account_key' => 'system:NGN:'.$kind,
                'user_id' => null, 'kind' => 'system', 'currency' => 'NGN',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('System wallet accounts require an approved retention procedure.');
    }
};
