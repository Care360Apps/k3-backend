<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Accept the mobile apps' base64 photos and conventional multipart uploads. */
class ProfileImageUpload
{
    private const MAX_BYTES = 1024000;

    public function store($image, string $directory): string
    {
        if ($image instanceof UploadedFile) {
            if (! $image->isValid() || $image->getSize() > self::MAX_BYTES) {
                $this->invalid();
            }
            $bytes = file_get_contents($image->getRealPath());
        } elseif (is_string($image)) {
            // Limit before decoding so an oversized request cannot allocate another huge buffer.
            if (strlen($image) > (int) ceil(self::MAX_BYTES / 3) * 4 + 64) {
                $this->invalid();
            }
            $encoded = preg_replace('#^data:image/(?:png|jpeg|jpg);base64,#i', '', $image);
            $bytes = base64_decode($encoded, true);
        } else {
            $this->invalid();
        }

        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            $this->invalid();
        }
        $info = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => null,
        };
        if (! $info || ! $extension || ($info['mime'] ?? null) !== $mime || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 16000000) {
            $this->invalid();
        }

        $name = bin2hex(random_bytes(16)).'.'.$extension;
        $written = file_put_contents(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name, $bytes, LOCK_EX);
        if ($written !== strlen($bytes)) {
            throw new RuntimeException('Could not save profile image.');
        }

        return $name;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['image' => 'Please select a JPEG or PNG image no larger than 1000 KB.']);
    }
}
