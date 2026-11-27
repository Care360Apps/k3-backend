<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PrivateAppointmentReportStorage
{
    public static function isPrivateFilename(string $filename): bool
    {
        return preg_match('/\Ak3report_[a-f0-9]{40}\.png\z/D', $filename) === 1;
    }

    public function discard(string $filename): void
    {
        if (! self::isPrivateFilename($filename)) {
            throw new RuntimeException('Invalid private report name.');
        }
        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        $folder = storage_path('app/private/medical/appointment-reports');
        $base = realpath($folder);
        $path = $folder.DIRECTORY_SEPARATOR.$filename;
        $full = realpath($path);
        if ($root === false || $public === false || $base === false || $full === false
            || ! str_starts_with($base, $root.DIRECTORY_SEPARATOR)
            || $base === $public || str_starts_with($base, $public.DIRECTORY_SEPARATOR)
            || ! str_starts_with($full, $base.DIRECTORY_SEPARATOR)
            || is_link($path) || ! is_file($full)) {
            throw new RuntimeException('Unsafe report cleanup path.');
        }
        if (! unlink($full)) {
            throw new RuntimeException('Report cleanup failed.');
        }
    }

    public function store(string $bytes): string
    {
        return ReportStorageLock::run(fn () => $this->storeLocked($bytes));
    }

    public function storeUpload(\Illuminate\Http\UploadedFile $file): string
    {
        if (!app(MedicalReportReleaseGate::class)->privateWritesEnabled()) {
            return (new \App\Http\Controllers\SuperAdmin\CustomController)->imageUpload($file);
        }
        if (!$file->isValid() || $file->getSize() > 5*1024*1024) throw ValidationException::withMessages(['report_image'=>'A PNG report of 5 MB or less is required.']);
        return $this->store(file_get_contents($file->getRealPath()));
    }

    private function storeLocked(string $bytes): string
    {
        if (strlen($bytes) > 5 * 1024 * 1024 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw ValidationException::withMessages(['report_image' => 'A valid PNG of 5 MB or less is required.']);
        }
        $image = @getimagesizefromstring($bytes);
        if (! $image || $image[2] !== IMAGETYPE_PNG || $image[0] > 10000 || $image[1] > 10000 || $image[0] * $image[1] > 16000000) {
            throw ValidationException::withMessages(['report_image' => 'Invalid report image.']);
        }

        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        if ($root === false || $public === false || $root === $public || str_starts_with($root, $public.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private report storage is not isolated.');
        }
        $directory = $root;
        foreach (['private', 'medical', 'appointment-reports'] as $part) {
            $directory .= DIRECTORY_SEPARATOR.$part;
            if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0700) && ! is_dir($directory))) {
                throw new RuntimeException('Private report directory unavailable.');
            }
            $resolved = realpath($directory);
            if ($resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Invalid report storage directory.');
            }
        }
        $filename = 'k3report_'.bin2hex(random_bytes(20)).'.png';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Unable to create report.');
        }
        try {
            if (! chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Unable to secure report.');
            }
        } catch (\Throwable $error) {
            fclose($stream);
            @unlink($path);
            throw $error;
        }
        fclose($stream);

        return $filename;
    }
}
