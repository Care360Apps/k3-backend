<?php

namespace App\Services;

/** Shared by migration, reconciliation and every private report upload. */
final class ReportStorageLock
{
    private static int $depth = 0;

    public static function run(callable $work): mixed
    {
        if (self::$depth > 0) {
            return $work();
        }
        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        if (! $root || ! $public || $root === $public || str_starts_with($root, $public.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Private lock root unavailable.');
        }
        $path = $root.DIRECTORY_SEPARATOR.'.medical-report-release.lock';
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe report lock.');
        }
        $stream = fopen($path, 'c');
        if (! $stream || ! chmod($path, 0600) || ! flock($stream, LOCK_EX | LOCK_NB)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException('Another report operation is running.');
        }
        self::$depth++;
        try {
            return $work();
        } finally {
            self::$depth--;
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}
