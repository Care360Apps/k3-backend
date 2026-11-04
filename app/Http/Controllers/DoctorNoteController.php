<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorNoteController extends Controller
{
    private function approvedClinician(Request $request): Doctor
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole('doctor')) {
            abort(403, 'Only approved medical doctors may access clinical notes.');
        }
        $doctor = Doctor::where('user_id', $user->id)
            ->where('user_role', 'doctor')
            ->where('is_approved', 1)
            ->where('status', 1)
            ->first();
        if (! $doctor) {
            abort(403, 'Doctor verification is required.');
        }

        return $doctor;
    }

    private function ownAppointment(int $appointmentId, Doctor $doctor): Appointment
    {
        return Appointment::where('doctor_id', $doctor->id)
            ->whereKey($appointmentId)
            ->firstOrFail();
    }

    private function noStore(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $doctor = $this->approvedClinician($request);
        $validated = $request->validate([
            'appointment_id' => 'required|integer|min:1',
        ]);
        $appointment = $this->ownAppointment($validated['appointment_id'], $doctor);
        $notes = DoctorNote::where('doctor_id', $doctor->id)
            ->where('appointment_id', $appointment->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $this->noStore(['success' => true, 'data' => $notes]);
    }

    public function store(Request $request): JsonResponse
    {
        $doctor = $this->approvedClinician($request);
        $data = $request->validate([
            'appointment_id' => 'required|integer|min:1',
            'title' => 'required|string|max:160',
            'body' => 'required|string|max:20000',
            'status' => 'sometimes|in:draft,final',
        ]);
        $appointment = $this->ownAppointment($data['appointment_id'], $doctor);
        $status = $data['status'] ?? 'draft';

        $note = DoctorNote::create([
            'doctor_id' => $doctor->id,
            'user_id' => $appointment->user_id, // Patient identity from owned appointment.
            'appointment_id' => $appointment->id,
            'title' => trim($data['title']),
            'body' => trim($data['body']),
            'status' => $status,
            'finalized_at' => $status === 'final' ? now() : null,
        ]);

        return $this->noStore(['success' => true, 'data' => [$note]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $doctor = $this->approvedClinician($request);
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:160',
            'body' => 'sometimes|required|string|max:20000',
            'status' => 'sometimes|in:draft,final',
        ]);
        if (! $data) {
            return $this->noStore(['success' => false, 'msg' => 'Nothing to update.'], 422);
        }

        $note = DB::transaction(function () use ($doctor, $id, $data) {
            $note = DoctorNote::where('doctor_id', $doctor->id)->whereKey($id)
                ->lockForUpdate()->firstOrFail();
            $this->ownAppointment((int) $note->appointment_id, $doctor);

            if ($note->status === 'final') {
                abort(409, 'Finalized clinical notes are immutable; create an addendum.');
            }
            $allowed = array_intersect_key($data, array_flip(['title', 'body', 'status']));
            foreach (['title', 'body'] as $field) {
                if (array_key_exists($field, $allowed)) {
                    $allowed[$field] = trim($allowed[$field]);
                }
            }
            if (($allowed['status'] ?? null) === 'final') {
                $allowed['finalized_at'] = now();
            }
            $note->update($allowed);

            return $note->refresh();
        });

        return $this->noStore(['success' => true, 'data' => [$note]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $doctor = $this->approvedClinician($request);
        DoctorNote::where('doctor_id', $doctor->id)->whereKey($id)->firstOrFail();

        return $this->noStore(['success' => false,
            'msg' => 'Clinical notes are retained for audit; deletion is unavailable.'], 405);
    }
}
