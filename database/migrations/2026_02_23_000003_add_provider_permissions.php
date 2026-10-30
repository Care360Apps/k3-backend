<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        $permissions = [
            'provider_type_access',
            'provider_type_add',
            'provider_type_edit',
            'provider_type_delete',
            'provider_access',
            'provider_add',
            'provider_edit',
            'provider_delete',
        ];

        $now = now();
        foreach ($permissions as $name) {
            if (! DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->exists()) {
                DB::table('permissions')->insert([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Artisan::call('cache:forget', ['key' => 'spatie.permission.cache']);

        $superAdminRole = DB::table('roles')->where('name', 'super admin')->where('guard_name', 'web')->first();
        if ($superAdminRole) {
            $permIds = DB::table('permissions')->whereIn('name', $permissions)->where('guard_name', 'web')->pluck('id');
            foreach ($permIds as $permId) {
                if (! DB::table('role_has_permissions')->where('permission_id', $permId)->where('role_id', $superAdminRole->id)->exists()) {
                    DB::table('role_has_permissions')->insert(['permission_id' => $permId, 'role_id' => $superAdminRole->id]);
                }
            }
        }
    }

    public function down()
    {
        $permissions = [
            'provider_type_access', 'provider_type_add', 'provider_type_edit', 'provider_type_delete',
            'provider_access', 'provider_add', 'provider_edit', 'provider_delete',
        ];
        $permIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('name', $permissions)->delete();
    }
};
