<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        if (DB::table('provider_types')->count() === 0) {
            $now = now();
            DB::table('provider_types')->insert([
                ['name' => 'ECG Technician', 'description' => 'Home ECG and heart monitoring services', 'icon' => 'fas fa-heartbeat', 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Physiotherapist', 'description' => 'Home physiotherapy and rehabilitation', 'icon' => 'fas fa-user-nurse', 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Home Nurse', 'description' => 'Nursing care at home', 'icon' => 'fas fa-user-nurse', 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Lab Technician', 'description' => 'Home sample collection for lab tests', 'icon' => 'fas fa-vial', 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down()
    {
        DB::table('provider_types')->truncate();
    }
};
