<?php
// Source-only gate: never silently convert local synthetic setup into staging
// or production seeding. Real integration requires disposable LOCAL database.
$path = __DIR__.'/../database/seeders/K3NearbyLocalDemoSeeder.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Local demo seeder missing');
}
$required = [
    "config('app.env') !== 'local'",
    "\$connection->getDatabaseName() !== 'care360_test'",
    "['127.0.0.1', 'localhost']",
    "I_UNDERSTAND_TEST_ONLY",
    "whereNotIn('email', \$emails)->exists()",
    "K3SyntheticLocalSeeder::class",
    "'offers_video_call' => \$video",
    "'offers_home_visit' => \$home",
    "'Home Nurse'",
    "'Synthetic Doctor'",
    "'Synthetic Provider'",
    "No practice coordinates are published here",
];
foreach ($required as $needle) {
    if (! str_contains($source, $needle)) {
        throw new RuntimeException('Synthetic safety preflight missing a required gate.');
    }
}
if (str_contains($source, 'INSERT INTO users') ||
    str_contains($source, 'DB::purge(') ||
    str_contains($source, 'DB::statement(')) {
    throw new RuntimeException('Unexpected raw database operation in demo seed.');
}
echo 'PASS: local-only fictional professional fixture guard'.PHP_EOL;
