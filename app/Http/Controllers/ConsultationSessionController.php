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
                && (int) $session->doctor_user_id === (int) $doctor->id, 404);
            abort_if($session->lifecycleState() !== 'active', 409, 'Consultation session closed');
            $uid = $session->participantUid((int) $request->user()->id);
            abort_if($uid === null, 404);
            $expires = min(now()->addMinutes(5)->timestamp, $session->expires_at->timestamp);
            abort_if($expires <= now()->timestamp, 409, 'Consultation session closed');
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

    // Invitations contain only a routing reference, never RTC credentials.
    public function invite(Request $request, int $appointmentId)
    {
        abort_unless(config('consultations.session_api_enabled') === true, 404);
        $recipient = DB::transaction(function () use ($request, $appointmentId) {
            [$appointment, $patient, $doctor] = $this->participants($request, $appointmentId);
            $session = ConsultationSession::where('appointment_id', $appointment->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $session->patient_user_id === (int) $patient->id
                && (int) $session->doctor_user_id === (int) $doctor->id, 404);
            abort_if($session->lifecycleState() !== 'active', 409);
            return (int) $request->user()->id === (int) $doctor->id ? $patient : $doctor;
        });
        $patientApp = ! $recipient->roles()->exists();
        $prefix = $patientApp ? 'patient' : 'doctor';
        $settings = Setting::first();
        $device = trim((string) $recipient->device_token);
        abort_unless($settings && (int) $settings->{$prefix.'_notification'} === 1
            && $device !== '' && strlen($device) <= 512, 503);
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Basic '.$settings->{$prefix.'_api_key'},
            ])->acceptJson()->connectTimeout(5)->timeout(10)
                ->withOptions(['verify' => true, 'allow_redirects' => false])
                ->post('https://onesignal.com/api/v1/notifications', [
                    'app_id' => $settings->{$prefix.'_app_id'},
                    'headings' => ['en' => 'Care360 consultation'],
                    'contents' => ['en' => 'Open Care360 to review your appointment.'],
                    'include_player_ids' => [$device],
                    'data' => ['type' => 'consultation_invite', 'appointment_id' => $appointmentId],
                ]);
            $result = $response->json();
            if (! $response->successful() || ! is_array($result)
                || ! is_string($result['id'] ?? null) || trim($result['id']) === ''
                || ! empty($result['errors'])
                || (isset($result['recipients']) && (int) $result['recipients'] < 1)) {
                throw new \RuntimeException('Notification request not accepted');
            }
        } catch (\Throwable $e) {
            report(new \RuntimeException('Consultation invitation delivery failed'));
            abort(503, 'Invitation unavailable');
        }
        return response()->json(['success' => true])->header('Cache-Control', 'no-store');
    }

    public function end(Request $request, int $appointmentId)
    {
        abort_unless(config('consultations.session_api_enabled') === true, 404);
        return DB::transaction(function () use ($request, $appointmentId) {
            [$appointment, $patient, $doctor] = $this->participants($request, $appointmentId);
            $session = ConsultationSession::where('appointment_id', $appointment->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $session->patient_user_id === (int) $patient->id
                && (int) $session->doctor_user_id === (int) $doctor->id, 404);
            if (! $session->ended_at) {
                $session->ended_at = now();
                $session->save();
            }
            return response()->json(['success' => true])->header('Cache-Control', 'no-store');
        });
    }

    public function status(Request $request, int $appointmentId)
    {
        abort_unless(config('consultations.session_api_enabled') === true, 404);

        return DB::transaction(function () use ($request, $appointmentId) {
            [$appointment, $patient, $doctor] = $this->participants($request, $appointmentId);
            $session = ConsultationSession::where('appointment_id', $appointment->id)->lockForUpdate()->first();
            if (! $session) {
                return response()->json(['success' => true, 'data' => [
                    'state' => 'not_started',
                ]])->header('Cache-Control', 'no-store');
            }
            abort_unless((int) $session->patient_user_id === (int) $patient->id
                && (int) $session->doctor_user_id === (int) $doctor->id, 404);
            return response()->json(['success' => true, 'data' => [
                'session_id' => $session->id,
                'state' => $session->lifecycleState(),
                'expires_at' => $session->expires_at->timestamp,
            ]])->header('Cache-Control', 'no-store');
        });
    }

    private function participants(Request $request, int $appointmentId): array
    {
        $appointment = Appointment::query()->whereKey($appointmentId)
            ->where('visit_type', 'video_call')
            ->where('appointment_status', 'approve')
            ->whereBetween('date', [now(env('timezone'))->subDay()->toDateString(), now(env('timezone'))->toDateString()])
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
