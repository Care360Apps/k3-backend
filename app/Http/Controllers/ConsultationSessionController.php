<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ConsultationSession;
use App\Models\Doctor;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ConsultationSessionController extends Controller
{
    public function credentials(Request $request, int $appointmentId)
    {
        abort_unless(config('consultations.session_api_enabled') === true, 404);

        return DB::transaction(function () use ($request, $appointmentId) {
            [$appointment, $patient, $doctor] = $this->participants($request, $appointmentId);
            $settings = Setting::select('agora_app_id', 'agora_app_certificate')->first();
            abort_unless($settings && preg_match('/^[a-f0-9]{32}$/i', (string) $settings->agora_app_id)
                && preg_match('/^[a-f0-9]{32}$/i', (string) $settings->agora_app_certificate), 503);
            $session = ConsultationSession::where('appointment_id', $appointment->id)->lockForUpdate()->first();
            if (! $session) {
                $session = ConsultationSession::create([
                    'appointment_id' => $appointment->id,
                    'channel' => 'c'.bin2hex(random_bytes(24)),
                    'patient_user_id' => $patient->id,
                    'doctor_user_id' => $doctor->id,
                    'expires_at' => now()->addHours(4),
                ]);
            }
            abort_unless((int) $session->patient_user_id === (int) $patient->id
                && (int) $session->doctor_user_id === (int) $doctor->id, 403);
            abort_if($session->ended_at || $session->expires_at->isPast(), 409, 'Consultation session closed');
            $uid = $session->participantUid((int) $request->user()->id);
            abort_if($uid === null, 403);
            $expires = min(now()->addMinutes(5)->timestamp, $session->expires_at->timestamp);
            require_once __DIR__.'/RtcTokenBuilder.php';
            $token = \RtcTokenBuilder::buildTokenWithUid(
                $settings->agora_app_id, $settings->agora_app_certificate,
                $session->channel, $uid, \RtcTokenBuilder::RolePublisher, $expires
            );
            abort_unless(is_string($token) && $token !== '', 503);
            return response()->json(['success' => true, 'data' => [
                'session_id' => $session->id, 'cn' => $session->channel,
                'uid' => $uid, 'agora_token' => $token,
                'appId' => $settings->agora_app_id, 'expires_at' => $expires,
            ]])->header('Cache-Control', 'no-store');
        });
    }

    public function end(Request $request, int $appointmentId)
    {
        abort_unless(config('consultations.session_api_enabled') === true, 404);
        return DB::transaction(function () use ($request, $appointmentId) {
            [$appointment, $patient, $doctor] = $this->participants($request, $appointmentId);
            $session = ConsultationSession::where('appointment_id', $appointment->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $session->patient_user_id === (int) $patient->id
                && (int) $session->doctor_user_id === (int) $doctor->id, 403);
            if (! $session->ended_at) {
                $session->ended_at = now();
                $session->save();
            }
            return response()->json(['success' => true])->header('Cache-Control', 'no-store');
        });
    }

    private function participants(Request $request, int $appointmentId): array
    {
        $appointment = Appointment::query()->whereKey($appointmentId)
            ->where('visit_type', 'video_call')
            ->whereIn('appointment_status', ['pending', 'approve'])
            ->where('date', '>=', now(env('timezone'))->subDay()->toDateString())
            ->lockForUpdate()->firstOrFail();
        $profile = Doctor::whereKey($appointment->doctor_id)
            ->where('is_approved', 1)->where('status', 1)->first();
        abort_unless($profile, 404);
        $patient = User::find($appointment->user_id);
        $doctor = User::find($profile->user_id);
        abort_unless($patient && $doctor && $patient->id !== $doctor->id
            && (int) $patient->status === 1 && (int) $doctor->status === 1
            && ! $patient->roles()->exists()
            && $doctor->hasRole('doctor') && ! $doctor->hasRole('provider')
            && in_array($profile->user_role, [null, 'doctor'], true)
            && in_array((int) $request->user()->id, [(int) $patient->id, (int) $doctor->id], true), 404);
        return [$appointment, $patient, $doctor];
    }
}
