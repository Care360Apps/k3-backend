<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\DoctorDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores the certification documents doctors and providers upload when registering.
 */
class DoctorDocumentService
{
    public const MAX_FILES = 5;

    public const MAX_KILOBYTES = 5120;

    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public static function rules(bool $required): array
    {
        return [
            'documents' => ($required ? 'required' : 'nullable').'|array|max:'.self::MAX_FILES,
            'documents.*' => 'file|mimes:'.implode(',', self::EXTENSIONS).'|max:'.self::MAX_KILOBYTES,
        ];
    }

    public static function messages(): array
    {
        return [
            'documents.required' => __('Please upload at least one certification document.'),
            'documents.max' => __('You can upload at most :max certification documents.'),
            'documents.*.mimes' => __('Certification documents must be PDF, JPG or PNG files.'),
            'documents.*.max' => __('Each certification document may not be larger than 5 MB.'),
        ];
    }

    /**
     * @param  UploadedFile[]  $files  already validated with rules()
     */
    public function storeMany(Doctor $doctor, array $files): void
    {
        foreach ($files as $file) {
            $extension = $file->guessExtension() === 'jpe' ? 'jpg' : $file->guessExtension();
            $path = $file->storeAs('doctor_documents/'.$doctor->id, Str::uuid().'.'.$extension, 'local');

            DoctorDocument::create([
                'doctor_id' => $doctor->id,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }
}
