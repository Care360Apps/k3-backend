<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Doctor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppointmentReportFileController extends Controller
{
    public function downloadReport(Request $request, int $appointmentId, string $name): BinaryFileResponse
    {
        $user = $request->user('api');
        abort_unless($user && (int) $user->status === 1, 401);
        $appointment = Appointment::query()->findOrFail($appointmentId);
        $isPatient = (int) $appointment->user_id === (int) $user->id;
        $isAssignedProfessional = $user->hasAnyRole(['doctor', 'provider']) && Doctor::query()
            ->where('user_id', $user->id)
            ->whereKey($appointment->doctor_id)
            ->where('is_approved', 1)
            ->where('status', 1)
            ->exists();
        abort_unless($isPatient || $isAssignedProfessional, 404);

        $names = json_decode($appointment->getRawOriginal('report_image') ?? '[]', true);
        abort_unless(is_array($names) && array_is_list($names) && in_array($name, $names, true)
            && \App\Services\PrivateAppointmentReportStorage::isPrivateFilename($name), 404);

        $folder = storage_path('app/private/medical/appointment-reports');
        $base = realpath($folder);
        $root = realpath(storage_path('app'));
        $public = realpath(public_path());
        $full = realpath($folder.DIRECTORY_SEPARATOR.$name);
        abort_unless($base !== false && $root !== false && $public !== false && $full !== false
            && $base === $root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'medical'.DIRECTORY_SEPARATOR.'appointment-reports' && str_starts_with($base, $root.DIRECTORY_SEPARATOR)
            && $base !== $public && ! str_starts_with($base, $public.DIRECTORY_SEPARATOR)
            && str_starts_with($full, $base.DIRECTORY_SEPARATOR)
            && is_file($full) && ! is_link($folder.DIRECTORY_SEPARATOR.$name), 404);

        foreach (['private', 'medical', 'appointment-reports'] as $part) {
            $root .= DIRECTORY_SEPARATOR.$part;
            abort_if(is_link($root),404);
        }
        return response()->download($full, 'care360-report.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
