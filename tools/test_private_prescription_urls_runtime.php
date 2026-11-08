<?php

/**
 * Executes the real private-prescription URL selector without booting Laravel,
 * using synthetic filenames only. No database, environment or patient data.
 */
use App\Services\PrivatePrescriptionStorage;

function url(string $path): string
{
    return 'https://care360.example.test/'.ltrim($path, '/');
}

require dirname(__DIR__).'/app/Services/PrivatePrescriptionStorage.php';

$private = 'k3priv_'.str_repeat('a', 40).'.pdf';
$legacy = str_repeat('b', 32).'.pdf';
$cases = [
    [$private, true, 'https://care360.example.test/api/medical-files/appointments/19/prescription'],
    [$legacy, false, 'https://care360.example.test/prescription/upload/'.$legacy],
    ['../secrets.pdf', false, ''],
    ['https://external.test/secret.pdf', false, ''],
    ['k3priv_not-a-valid-hex.pdf', false, ''],
    ['', false, ''],
];
foreach ($cases as [$name, $isPrivate, $expected]) {
    if (PrivatePrescriptionStorage::isPrivateFilename($name) !== $isPrivate) {
        fwrite(STDERR, 'FAIL: file-provenance classification'.PHP_EOL);
        exit(1);
    }
    $actual = PrivatePrescriptionStorage::appointmentUrl(19, $name);
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: appointment URL selection'.PHP_EOL);
        exit(1);
    }
}

foreach (range(1, 50) as $i) {
    $new = 'k3priv_'.bin2hex(random_bytes(20)).'.pdf';
    if (! PrivatePrescriptionStorage::isPrivateFilename($new) ||
        str_contains(PrivatePrescriptionStorage::appointmentUrl($i, $new), '/prescription/upload/')) {
        fwrite(STDERR, 'FAIL: randomized private file resolved publicly'.PHP_EOL);
        exit(1);
    }
}
echo 'PASS: private and legacy URL separation, path rejection, and randomized private links.'.PHP_EOL;
