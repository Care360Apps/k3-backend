<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * LOCAL only. Demonstrates real Doctor/Provider availability against the
 * isolated Laravel API using fictional people. No clinical or payment data.
 * Run after importing a reviewed schema-only local database baseline.
 */
final class K3NearbyLocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $connection = DB::connection();
        $host = (string) config('database.connections.mysql.host');

        if (config('app.env') !== 'local'
            || $connection->getDatabaseName() !== 'care360_test'
            || ! in_array($host, ['127.0.0.1', 'localhost'], true)
            || env('K3_LOCAL_SYNTHETIC_SEED') !== 'I_UNDERSTAND_TEST_ONLY') {
            throw new RuntimeException('Refusing demo seed outside a disposable loopback LOCAL database.');
        }

        $emails = [
            'patient@care360.example.test',
            'doctor@care360.example.test',
            'provider@care360.example.test',
        ];
        if (DB::table('users')->whereNotIn('email', $emails)->exists()) {
            throw new RuntimeException('Unexpected non-synthetic users in care360_test. Refusing to alter this database.');
        }

        foreach (['is_approved', 'status', 'is_filled', 'subscription_status',
                  'user_role', 'offers_video_call', 'offers_home_visit'] as $column) {
            if (! Schema::hasColumn('doctor', $column)) {
                throw new RuntimeException('Review and apply missing doctor eligibility migration: '.$column);
            }
        }

        // User/role/profile fixture creates ONLY three reserved identities and
        // requires the caller to supply a 16+ character local test password.
        $this->call(K3SyntheticLocalSeeder::class);

        $expected = [
            ['doctor@care360.example.test', 'Synthetic Doctor', 'doctor', 1, 0],
            ['provider@care360.example.test', 'Synthetic Provider', 'provider', 1, 1],
        ];
        foreach ($expected as [$email, $name, $role, $video, $home]) {
            $user = DB::table('users')->where('email', $email)->first();
            if (! $user || $user->name !== $name) {
                throw new RuntimeException('Expected synthetic professional identity unavailable.');
            }

            $profile = DB::table('doctor')->where('user_id', $user->id)->first();
            if (! $profile || $profile->name !== $name ||
                ! in_array($profile->user_role, [$role, null], true)) {
                throw new RuntimeException('Unexpected doctor/provider profile; demo seed refused.');
            }

            $roleExists = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->where('model_has_roles.model_id', $user->id)
                ->where('roles.name', $role)->exists();
            if (! $roleExists) {
                throw new RuntimeException('Synthetic professional role association missing.');
            }

            $updates = [
                'user_role' => $role,
                'is_approved' => 1,
                'status' => 1,
                'is_filled' => 1,
                'subscription_status' => 1,
                'offers_video_call' => $video,
                'offers_home_visit' => $home,
                'updated_at' => now(),
            ];
            if ($role === 'provider') {
                $homeNurseId = DB::table('provider_types')
                    ->where('name', 'Home Nurse')
                    ->where('status', 1)
                    ->value('id');
                if (! $homeNurseId) {
                    throw new RuntimeException('Synthetic provider types must be seeded from local migrations first.');
                }
                $updates['provider_type_id'] = $homeNurseId;
            }
            DB::table('doctor')->where('id', $profile->id)->update($updates);
        }

        // No practice coordinates are published here: providers must opt in
        // using the actual Care360 Plus interface and a fictitious service area.
        $this->command?->info('Local demo: Patient/Doctor/Provider eligible; all availability remains OFF.');
    }
}
