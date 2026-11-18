<?php

// Static offline guard. No production environment read and no credentials.
$src = file_get_contents(__DIR__.'/../app/Console/Commands/Care360SecurityPreflight.php');
if (! is_string($src)) {
    throw new RuntimeException('Security preflight command missing.');
}
foreach ([
    "config('app.debug') === false",
    "config('app.url')",
    'realpath(public_path())',
    "storage_path('app')",
    'fileperms($file)',
    'privateKeyPermissions',
    'NOT VERIFIED HERE:',
] as $expected) {
    if (! str_contains($src, $expected)) {
        throw new RuntimeException('Missing offline deployment guard.');
    }
}
foreach ([
    'file_get_contents($file)',
    'readfile(',
    'env(',
    'var_dump(',
    'print_r(',
    'shell_exec(',
    'passthru(',
    'Artisan::call(',
] as $prohibited) {
    if (str_contains($src, $prohibited)) {
        throw new RuntimeException('Preflight could expose secrets or mutate production.');
    }
}
echo "PASS: read-only production preflight source gate; real server status unknown.".PHP_EOL;
