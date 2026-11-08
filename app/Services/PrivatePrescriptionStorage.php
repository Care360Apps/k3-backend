<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Files are stored outside the publicly served Laravel web root.
 * This class does not migrate older documents or handle laboratory reports.
 */
final class PrivatePrescriptionStorage
{
    public static function enabled(): bool
    {
        return config('medical_files.private_prescription_writes', false) === true;
    }

    public static function appointmentUrl(int $appointmentId, ?string $filename): string
    {
        if (! $filename) {
            return '';
        }

        return self::enabled()
            ? url('api/medical-files/appointments/'.$appointmentId.'/prescription')
            : url('prescription/upload/'.$filename);
    }

    public function store(UploadedFile $upload): string
    {
        if (! $upload->isValid()
            || ! is_int($upload->getSize())
            || $upload->getSize() < 5
            || $upload->getSize() > 10 * 1024 * 1024
            || ! in_array($upload->getMimeType(), ['application/pdf', 'application/x-pdf'], true)) {
            throw ValidationException::withMessages([
                'pdf' => 'A valid PDF of 10 MB or less is required.',
            ]);
        }

        $stream = fopen($upload->getRealPath(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['pdf' => 'Invalid PDF upload.']);
        }
        try {
            $header = fread($stream, 5);
        } finally {
            fclose($stream);
        }
        if ($header !== '%PDF-') {
            throw ValidationException::withMessages(['pdf' => 'Invalid PDF upload.']);
        }

        $root = storage_path('app');
        $parent = $root;
        foreach (['private', 'medical', 'prescriptions'] as $part) {
            $path = $parent.DIRECTORY_SEPARATOR.$part;
            if (is_link($path)) {
                throw new RuntimeException('Private document storage must not contain links.');
            }
            if (! is_dir($path) && ! mkdir($path, 0700) && ! is_dir($path)) {
                throw new RuntimeException('Private document storage unavailable.');
            }
            if (realpath($path) === false ||
                ! str_starts_with(realpath($path), realpath($root).DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Invalid private storage directory.');
            }
            $parent = $path;
        }

        $filename = bin2hex(random_bytes(20)).'.pdf';
        $moved = $upload->move($parent, $filename);
        if (! chmod($moved->getPathname(), 0600)) {
            @unlink($moved->getPathname());
            throw new RuntimeException('Unable to secure private document.');
        }

        return $filename;
    }

    /**
     * Cleanup only files created by this writer after a failed DB operation.
     * Never accept arbitrary file paths from a request or a client.
     */
    public function discard(string $filename): void
    {
        if (preg_match('/\A[a-f0-9]{40}\.pdf\z/D', $filename) !== 1) {
            return;
        }
        $folder = storage_path('app/private/medical/prescriptions');
        $file = $folder.DIRECTORY_SEPARATOR.$filename;
        if (! is_link($file) && is_file($file) &&
            realpath($folder) !== false &&
            str_starts_with(realpath($file) ?: '', realpath($folder).DIRECTORY_SEPARATOR)) {
            @unlink($file);
        }
    }
}
