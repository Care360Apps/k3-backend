<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\DoctorDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DoctorDocumentController extends Controller
{
    /**
     * Streams a certification document to admins who can manage doctors/providers, or to its owner.
     */
    public function show(Request $request, $id)
    {
        $document = DoctorDocument::with('doctor')->findOrFail($id);
        $doctor = $document->doctor;
        $user = auth()->user();

        $permission = $doctor?->user_role === 'provider' ? 'provider_access' : 'doctor_access';
        $allowed = $user->hasRole('super admin')
            || ($doctor && $doctor->user_id === $user->id)
            || $user->can($permission);
        abort_unless($allowed, Response::HTTP_FORBIDDEN, '403 Forbidden');
        abort_unless(Storage::disk('local')->exists($document->path), Response::HTTP_NOT_FOUND);

        return Storage::disk('local')->response(
            $document->path,
            $document->original_name,
            ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff'],
            $request->boolean('download') ? 'attachment' : 'inline'
        );
    }
}
