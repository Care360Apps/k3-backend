<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Disposable synthetic identities for isolated Care360 LOCAL development.
 * Never run against production, an existing staging database or real records.
 *
 * Set K3_LOCAL_SYNTHETIC_SEED=I_UNDERSTAND_TEST_ONLY and a new
 * K3_SYNTHETIC_PASSWORD (at least 16 characters) in a private local .env.
 *
 * Run: php artisan db:seed --class="Database\Seeders\K3SyntheticLocalSeeder"
 */
class K3SyntheticLocalSeeder extends Seeder
{
    public function run(): void
    {
        $connection = DB::connection();
        $host = (string) config('database.connections.mysql.host');

        if (config('app.env') !== 'local'
            || $connection->getDatabaseName() !== 'care360_test'
            || ! in_array($host, ['127.0.0.1', 'localhost'], true)
            || env('K3_LOCAL_SYNTHETIC_SEED') !== 'I_UNDERSTAND_TEST_ONLY') {
            throw new RuntimeException('Refusing to seed: isolated LOCAL database guard failed.');
        }

        $password = (string) env('K3_SYNTHETIC_PASSWORD', '');
        if (strlen($password) < 16) {
            throw new RuntimeException('Set K3_SYNTHETIC_PASSWORD to a unique value of at least 16 characters.');
        }

        $now = now();
        $identities = [
            ['patient', 'patient@care360.example.test', 'Synthetic Patient', '00000000001'],
            ['doctor', 'doctor@care360.example.test', 'Synthetic Doctor', '00000000002'],
            ['provider', 'provider@care360.example.test', 'Synthetic Provider', '00000000003'],
        ];

        foreach ($identities as [$kind, $email, $name, $phone]) {
            $existing = DB::table('users')->where('email', $email)->first();

            if ($existing) {
                if ($existing->name !== $name) {
                    throw new RuntimeException("Synthetic account collision: {$email}");
                }
                $userId = $existing->id;
            } else {
                $userId = DB::table('users')->insertGetId([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'phone_code' => '+234',
                    'password' => Hash::make($password),
                    'verify' => 1,
                    'status' => 1,
                    'image' => 'defaultUser.png',
                    'dob' => '1990-01-01',
                    'gender' => 'other',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($kind === 'patient') {
                continue; // In this codebase, patients must not have a Spatie role.
            }

            $roleId = DB::table('roles')->where('name', $kind)
                ->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                throw new RuntimeException("Missing {$kind} role: import local defaults first.");
            }

            $roleLink = [
                'role_id' => $roleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $userId,
            ];

            if (! DB::table('model_has_roles')->where($roleLink)->exists()) {
                DB::table('model_has_roles')->insert($roleLink);
            }

            if (! DB::table('doctor')->where('user_id', $userId)->exists()) {
                $profile = [
                    'user_id' => $userId,
                    'name' => $name,
                    'image' => 'defaultUser.png',
                    'since' => 'Synthetic local test identity',
                    'status' => 1,
                    'is_approved' => 1,
                    'based_on' => 'commission',
                    'is_filled' => 1,
                    'subscription_status' => 1,
                    'user_role' => $kind,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($kind === 'provider') {
                    $profile['provider_type_id'] = DB::table('provider_types')
                        ->where('name', 'Home Nurse')->value('id');
                }

                DB::table('doctor')->insert($profile);
            }
        }

        $this->command?->info('Three synthetic LOCAL test identities seeded; password not displayed.');
    }
}
