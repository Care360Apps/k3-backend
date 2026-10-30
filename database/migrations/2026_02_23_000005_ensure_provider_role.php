<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        if (! DB::table('roles')->where('name', 'provider')->exists()) {
            DB::table('roles')->insert([
                'name' => 'provider',
                'guard_name' => 'web',
                'default' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('roles')->where('name', 'provider')->delete();
    }
};
