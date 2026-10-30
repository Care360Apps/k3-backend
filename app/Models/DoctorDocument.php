<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A certification document uploaded by a doctor or provider at registration.
 * Files live on the private "local" disk and are only served through DoctorDocumentController.
 */
class DoctorDocument extends Model
{
    protected $fillable = ['doctor_id', 'original_name', 'path', 'mime_type', 'size'];

    protected $hidden = ['path'];

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    protected static function booted()
    {
        static::deleted(function (DoctorDocument $document) {
            Storage::disk('local')->delete($document->path);
        });
    }
}
