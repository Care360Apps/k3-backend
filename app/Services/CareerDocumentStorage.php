<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;

final class CareerDocumentStorage
{
    private function folder(): string
    {
        $root = storage_path('app/private/career');
        foreach ([storage_path('app'), storage_path('app/private'), $root] as $p) {
            if (is_link($p)) {
                throw new \RuntimeException('Unsafe private storage configuration.');
            }
            if (! is_dir($p) && ! mkdir($p, 0700, true) && ! is_dir($p)) {
                throw new \RuntimeException('Private storage unavailable.');
            }
        }
        $path = realpath($root);
        $public = realpath(public_path());
        if (! $path || ! $public || $path === $public || str_starts_with($path, $public.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Private storage unavailable.');
        }

        return $path;
    }

    public function store(UploadedFile $file): array
    {
        $bytes = file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes) && strlen($bytes) > 0 && strlen($bytes) <= 5 * 1024 * 1024, 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true), 422);
        $name = bin2hex(random_bytes(24)).'.enc';
        $path = $this->folder().DIRECTORY_SEPARATOR.$name;
        $encrypted = Crypt::encryptString(base64_encode($bytes));
        if (file_put_contents($path, $encrypted, LOCK_EX) !== strlen($encrypted)) {
            @unlink($path);
            throw new \RuntimeException('Private upload could not be saved.');
        }
        @chmod($path, 0600);

        return ['storage_name' => $name, 'mime' => $mime, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    public function remove(string $name): void
    {
        if (preg_match('/^[a-f0-9]{48}\.enc$/D', $name)) {
            @unlink($this->folder().DIRECTORY_SEPARATOR.$name);
        }
    }

    public function erase(string $name): void
    {
        if (! preg_match('/^[a-f0-9]{48}\.enc$/D', $name)) {
            throw new \RuntimeException('Invalid deletion reference.');
        }
        $path = $this->folder().DIRECTORY_SEPARATOR.$name;
        if (is_link($path)) {
            throw new \RuntimeException('Private storage requires operator review.');
        }
        if (is_file($path) && ! @unlink($path)) {
            throw new \RuntimeException('Private document deletion is incomplete. Retry after storage review.');
        }
        if (file_exists($path)) {
            throw new \RuntimeException('Private document deletion is incomplete.');
        }
    }

    public function read(object $document)
    {
        abort_unless(preg_match('/^[a-f0-9]{48}\.enc$/D', $document->storage_name), 404);
        $path = $this->folder().DIRECTORY_SEPARATOR.$document->storage_name;
        abort_unless(is_file($path) && ! is_link($path) && filesize($path) <= 8 * 1024 * 1024, 404);
        try {
            $bytes = base64_decode(Crypt::decryptString(file_get_contents($path)), true);
        } catch (\Throwable $e) {
            abort(404);
        }
        abort_unless(is_string($bytes) && strlen($bytes) === (int) $document->bytes
            && hash_equals($document->sha256, hash('sha256', $bytes)), 404);
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$document->mime] ?? null;
        abort_unless($ext, 404);

        return response($bytes)->header('Content-Type', $document->mime)
            ->header('Content-Disposition', 'attachment; filename="care360-document.'.$ext.'"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
