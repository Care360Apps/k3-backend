<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Prescription;
use App\Services\PrivatePrescriptionStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 1 of medical-document privacy migration.
 *
 * Only serves files that are already in private storage; it deliberately
 * NEVER falls back to public/prescription/upload. Existing public documents
 * need a separately approved, audited, reversible migration.
 */
class PatientMedicalFileController extends Controller
{
    public function downloadPrescription(Request $request, int $appointmentId): BinaryFileResponse
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        $appointment = Appointment::query()
            ->select(['id', 'user_id', 'doctor_id'])
            ->findOrFail($appointmentId);

        $isPatient = (int) $appointment->user_id === (int) $user->id;
        // An assigned doctor/provider has access to this appointment only.
        $isAssignedProfessional = ! $isPatient && (int) $user->status === 1
            && $user->hasRole('doctor') && Doctor::query()
            ->where('user_id', $user->id)
            ->whereKey($appointment->doctor_id)
            // Disabled or approval-revoked clinical accounts must lose access
            // even to appointments that were assigned while approved.
            ->where('is_approved', 1)
            ->where('status', 1)
            ->where(function ($query) {
                // Older approved doctor records may not have a populated
                // role column. Explicit provider profiles are not prescribers.
                $query->whereNull('user_role')->orWhere('user_role', '!=', 'provider');
            })
            ->exists();
        abort_unless($isPatient || $isAssignedProfessional, 404);

        $prescription = Prescription::query()
            ->where('appointment_id', $appointment->id)
            ->where('user_id', $appointment->user_id)
            ->firstOrFail(['id', 'pdf']);

        $name = (string) $prescription->pdf;
        // Restrict names to server-generated PDFs; never accept request paths,
        // user-provided filenames, traversal sequences or arbitrary extensions.
        abort_unless(
            PrivatePrescriptionStorage::isPrivateFilename($name),
            404
        );

        $folder = storage_path('app/private/medical/prescriptions');
        $base = realpath($folder);
        $full = realpath($folder.DIRECTORY_SEPARATOR.$name);
        abort_unless(
            $base !== false && $full !== false &&
            str_starts_with($full, $base.DIRECTORY_SEPARATOR) &&
            is_file($full) && ! is_link($folder.DIRECTORY_SEPARATOR.$name),
            404
        );

        // Never follow an administrative URL or a public-file fallback.
        // No signed query-token is accepted: revocation takes effect at the next request.
        return response()->download($full, 'care360-prescription.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}