<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Privacy-first, opt-in nearby-care pilot.
 *
 * "Accepting requests" means the professional has opted into a bounded shift,
 * NOT that a consultation slot or home-visit booking has been confirmed.
 * Location is a separately consented PUBLIC SERVICE AREA, never device GPS.
 */
final class NearbyCareController extends Controller
{
    private function ownedProfessional(Request $request): Doctor
    {
        $user = $request->user('api');
        abort_unless(
            $user && ($user->hasRole('doctor') || $user->hasRole('provider')),
            403, 'Professional account required'
        );
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        // Both Passport user role and linked professional type must agree.
        // Neither a Patient nor a misconfigured account may toggle someone
        // else's or a different professional role's public presence.
        $provider = $user->hasRole('provider');
        abort_unless(
            $provider
                ? $doctor->user_role === 'provider'
                : ($doctor->user_role === 'doctor' || $doctor->user_role === null),
            403
        );

        return $doctor;
    }

    private function presenceResponse($record): array
    {
        return [
            'accepting_requests' => $record !== null &&
                (bool) $record->accepting_requests &&
                $record->on_duty_until !== null &&
                \Carbon\Carbon::parse($record->on_duty_until)->isFuture(),
            'public_lat' => $record->public_lat ?? null,
            'public_lng' => $record->public_lng ?? null,
            'service_radius_km' => $record->service_radius_km ?? 10,
            'on_duty_until' => $record !== null && $record->on_duty_until !== null
                ? \Carbon\Carbon::parse($record->on_duty_until)->utc()->toIso8601String()
                : null,
            'public_location_consent_at' => $record->public_location_consent_at ?? null,
        ];
    }

    public function myStatus(Request $request)
    {
        $doctor = $this->ownedProfessional($request);
        $record = DB::table('professional_service_presence')
            ->where('doctor_id', $doctor->id)->first();

        return response()->json([
            'success' => true,
            'data' => $this->presenceResponse($record),
        ]);
    }

    public function update(Request $request)
    {
        $doctor = $this->ownedProfessional($request);
        $data = $request->validate([
            'accepting_requests' => 'required|boolean',
            'public_lat' => 'required_if:accepting_requests,1|nullable|numeric|between:-90,90',
            'public_lng' => 'required_if:accepting_requests,1|nullable|numeric|between:-180,180',
            'service_radius_km' => 'nullable|integer|between:1,50',
            'minutes_on_duty' => 'nullable|integer|between:15,240',
            'confirm_public_location' => 'required_if:accepting_requests,1|accepted',
        ]);
        $online = (bool) $data['accepting_requests'];

        if ($online) {
            abort_unless(
                (int) $doctor->status === 1 &&
                (int) $doctor->is_approved === 1 &&
                (int) $doctor->is_filled === 1 &&
                (int) $doctor->subscription_status === 1,
                403, 'Professional must be verified and active'
            );
            $isProvider = $doctor->user_role === 'provider';
            // Match existing ConsultationCapabilities: legacy Doctor rows
            // with a NULL video flag may still offer virtual consultations.
            $offersVirtual = $doctor->offers_video_call === null
                ? ! $isProvider : (int) $doctor->offers_video_call === 1;
            abort_unless(
                $offersVirtual ||
                ($isProvider && (int) $doctor->offers_home_visit === 1),
                403, 'No published eligible service'
            );
            // The published point is intentionally coarse at storage time.
            // A precise database coordinate plus repeated distance probes
            // would let outsiders reconstruct an address despite a rounded
            // map pin. Private meeting points belong only in the booking
            // workflow after the professional accepts the request.
            $lat = round((float) $data['public_lat'], 2);
            $lng = round((float) $data['public_lng'], 2);
            if ($lat === 0.0 && $lng === 0.0) {
                throw ValidationException::withMessages([
                    'public_lat' => 'Choose a real public practice or service-area location.',
                ]);
            }
        }

        $now = now();
        $values = [
            'accepting_requests' => $online,
            'on_duty_until' => $online
                ? $now->copy()->addMinutes((int) ($data['minutes_on_duty'] ?? 60))
                : null,
            'updated_at' => $now,
        ];
        if ($online) {
            // Published coordinates must be explicitly chosen/confirmed by the
            // professional, NOT silently copied from their registered address.
            $values['public_lat'] = $lat;
            $values['public_lng'] = $lng;
            $values['service_radius_km'] = (int) ($data['service_radius_km'] ?? 10);
            $values['public_location_consent_at'] = $now;
        }

        DB::table('professional_service_presence')->upsert(
            [array_merge([
                'doctor_id' => $doctor->id,
                'created_at' => $now,
            ], $values)],
            ['doctor_id'],
            array_keys($values)
        );
        $record = DB::table('professional_service_presence')
            ->where('doctor_id', $doctor->id)->first();

        return response()->json([
            'success' => true,
            'data' => $this->presenceResponse($record),
        ]);
    }

    public function nearby(Request $request)
    {
        $input = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius_km' => 'nullable|numeric|between:1,50',
            'role' => 'nullable|in:doctor,provider',
            'visit_type' => 'nullable|in:video_call,home_visit',
            'limit' => 'nullable|integer|between:1,50',
        ]);
        $latitude = (float) $input['lat'];
        $longitude = (float) $input['lng'];
        $radius = (float) ($input['radius_km'] ?? 20);
        $limit = (int) ($input['limit'] ?? 30);

        // Cheap indexed bounding box first, exact great-circle distance next.
        $latDelta = $radius / 111.2;
        $lngDelta = min(180, $radius / max(0.01, 111.2 * cos(deg2rad($latitude))));
        $distanceSql = '(6371 * ACOS(LEAST(1, GREATEST(-1, '.
            'COS(RADIANS(?)) * COS(RADIANS(p.public_lat)) * '.
            'COS(RADIANS(p.public_lng) - RADIANS(?)) + '.
            'SIN(RADIANS(?)) * SIN(RADIANS(p.public_lat))))))';

        $query = DB::table('professional_service_presence as p')
            ->join('doctor as d', 'd.id', '=', 'p.doctor_id')
            ->where('p.accepting_requests', 1)
            ->where('p.on_duty_until', '>', now())
            ->whereNotNull('p.public_location_consent_at')
            ->whereNotNull('p.public_lat')
            ->whereNotNull('p.public_lng')
            ->where('d.status', 1)
            ->where(function ($q) {
                $q->where('d.user_role', 'provider')
                    ->orWhere('d.user_role', 'doctor')
                    ->orWhereNull('d.user_role');
            })
            ->where(function ($q) {
                $q->where('d.offers_video_call', 1)
                    ->orWhere(function ($legacyDoctor) {
                        $legacyDoctor->whereNull('d.offers_video_call')
                            ->where(function ($role) {
                                $role->where('d.user_role', 'doctor')
                                    ->orWhereNull('d.user_role');
                            });
                    })
                    ->orWhere(function ($provider) {
                        $provider->where('d.user_role', 'provider')
                            ->where('d.offers_home_visit', 1);
                    });
            })
            ->where('d.is_approved', 1)
            ->where('d.is_filled', 1)
            ->where('d.subscription_status', 1)
            ->whereBetween('p.public_lat', [
                max(-90, $latitude - $latDelta),
                min(90, $latitude + $latDelta),
            ]);
        // Also bound longitude to avoid querying all east/west candidates.
        // The split predicate safely covers the international date line.
        if ($longitude - $lngDelta < -180) {
            $query->where(function ($q) use ($longitude, $lngDelta) {
                $q->where('p.public_lng', '>=', $longitude - $lngDelta + 360)
                    ->orWhere('p.public_lng', '<=', $longitude + $lngDelta);
            });
        } elseif ($longitude + $lngDelta > 180) {
            $query->where(function ($q) use ($longitude, $lngDelta) {
                $q->where('p.public_lng', '>=', $longitude - $lngDelta)
                    ->orWhere('p.public_lng', '<=', $longitude + $lngDelta - 360);
            });
        } else {
            $query->whereBetween('p.public_lng', [
                $longitude - $lngDelta, $longitude + $lngDelta,
            ]);
        }
        $query->select([
                'd.id', 'd.name', 'd.user_role', 'd.provider_type_id',
                'd.offers_video_call', 'd.offers_home_visit',
                'd.appointment_fees', 'd.hourly_rate',
                'p.public_lat', 'p.public_lng',
                'p.service_radius_km', 'p.on_duty_until',
            ])
            ->selectRaw($distanceSql.' AS distance_km', [
                $latitude, $longitude, $latitude,
            ])
            ->havingRaw('distance_km <= ?', [$radius]);

        if (isset($input['role'])) {
            if ($input['role'] === 'provider') {
                $query->where('d.user_role', 'provider');
            } else {
                $query->where(function ($q) {
                    $q->where('d.user_role', 'doctor')->orWhereNull('d.user_role');
                });
            }
        }
        if (($input['visit_type'] ?? null) === 'home_visit') {
            $query->where('d.user_role', 'provider')
                ->where('d.offers_home_visit', 1)
                ->havingRaw('distance_km <= service_radius_km');
        } elseif (($input['visit_type'] ?? null) === 'video_call') {
            $query->where(function ($q) {
                $q->where('d.offers_video_call', 1)
                    ->orWhere(function ($legacyDoctor) {
                        $legacyDoctor->whereNull('d.offers_video_call')
                            ->where(function ($role) {
                                $role->where('d.user_role', 'doctor')
                                    ->orWhereNull('d.user_role');
                            });
                    });
            });
        }
        // MySQL/MariaDB supported by the existing Namecheap Laravel service.
        // Use bounded, indexed query; no access to private live device GPS.
        $records = $query->orderBy('distance_km')->limit($limit)->get();
        $results = [];
        foreach ($records as $row) {
            $isProvider = $row->user_role === 'provider';
            $results[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'role' => $isProvider ? 'provider' : 'doctor',
                'provider_type_id' => $isProvider ? $row->provider_type_id : null,
                // Coarse display coordinates; never expose the original
                // six-decimal point publicly or continuous live movement.
                'lat' => round((float) $row->public_lat, 2),
                'lng' => round((float) $row->public_lng, 2),
                'distance_km' => round((float) $row->distance_km, 1),
                'offers_video_call' => $row->offers_video_call === null
                    ? ! $isProvider : (bool) $row->offers_video_call,
                'offers_home_visit' => $isProvider && (bool) $row->offers_home_visit,
                'service_radius_km' => (int) $row->service_radius_km,
                'accepting_requests' => true,
                'on_duty_until' => \Carbon\Carbon::parse($row->on_duty_until)
                    ->utc()->toIso8601String(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $results,
            'meta' => [
                'distance_unit' => 'km',
                'distance_type' => 'approximate_straight_line',
                'availability' => 'accepting_requests_not_booking_confirmed',
                'public_location_precision' => 'coarse',
            ],
        ]);
    }
}
