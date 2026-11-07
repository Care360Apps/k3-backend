<?php

namespace App\Http\Controllers;

use App\Models\WorkforceAccount;
use App\Models\WorkforceCandidate;
use App\Models\WorkforceEmployer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WorkforcePilotController extends Controller
{
    public function status()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'applications_open' => (bool) config('workforce.enabled', false),
                'candidate_verification' => 'manual_review',
                'employer_verification' => 'manual_review',
            ],
        ]);
    }

    public function register(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email', ''))),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:workforce_accounts,email'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'account_type' => ['required', Rule::in(['candidate', 'employer'])],
            'adult_attestation' => ['accepted'],
            'workforce_terms_accepted' => ['accepted'],
        ]);

        // Store the minimum required identity. Never import Patient clinical
        // fields or create Passport credentials for an employer.
        DB::transaction(function () use ($data) {
            $actor = WorkforceAccount::create([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'account_type' => $data['account_type'],
            ]);
            if ($actor->account_type === 'candidate') {
                WorkforceCandidate::create(['account_id' => $actor->id]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Workforce account created. Credential verification is required before any public listing or hiring.',
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string'],
        ]);
        $email = Str::lower(trim($data['email']));
        $actor = WorkforceAccount::query()->where('email', $email)->first();

        // Generic response prevents probing whether an employer/student exists.
        if (! $actor || ! $actor->is_active || ! Hash::check($data['password'], $actor->password)) {
            return response()->json([
                'success' => false, 'code' => 'INVALID_CREDENTIALS',
                'message' => 'Invalid email or password.',
            ], 401);
        }

        $raw = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $expires = now()->addHours((int) config('workforce.session_hours', 12));
        DB::table('workforce_sessions')->insert([
            'account_id' => $actor->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => $expires,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json([
            'success' => true,
            'data' => [
                'token' => $raw,
                'expires_at' => $expires->toIso8601String(),
                'role' => $actor->account_type,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $actor = $this->actor($request);
        $profile = $actor->account_type === 'candidate'
            ? $actor->candidate
            : $actor->employer;
        return response()->json([
            'success' => true,
            'data' => [
                'name' => $actor->name,
                'email' => $actor->email,
                'account_type' => $actor->account_type,
                'email_verified' => $actor->email_verified_at !== null,
                'profile' => $profile,
            ],
        ]);
    }

    public function candidateProfile(Request $request)
    {
        $actor = $this->actor($request);
        if ($actor->account_type !== 'candidate') {
            return response()->json(['success' => false, 'code' => 'FORBIDDEN'], 403);
        }

        $data = $request->validate([
            'career_stage' => ['required', Rule::in(['student', 'graduate', 'licensed'])],
            'institution' => ['required', 'string', 'min:3', 'max:150'],
            'qualification' => ['required', 'string', 'min:3', 'max:150'],
            'desired_role' => ['required', 'string', 'min:3', 'max:100'],
            'state' => ['required', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'shift_preference' => ['required', Rule::in(['weekdays', 'weekends', 'nights', 'flexible'])],
            'available_for_work' => ['required', 'boolean'],
            'consent_to_list' => ['required', 'boolean'],
        ]);

        // Whitelist candidate-editable fields. Server approval, licence
        // verification, regulator and expiry are not writable by applicants.
        $profile = WorkforceCandidate::query()->firstOrCreate(['account_id' => $actor->id]);
        $profile->fill($data);
        $profile->save();

        return response()->json([
            'success' => true,
            'message' => 'Career profile saved. It will not appear to employers until independently verified.',
            'data' => [
                'verification_status' => $profile->verification_status,
                'available_for_work' => $profile->available_for_work,
                'consent_to_list' => $profile->consent_to_list,
            ],
        ]);
    }

    public function employerProfile(Request $request)
    {
        $actor = $this->actor($request);
        if ($actor->account_type !== 'employer') {
            return response()->json(['success' => false, 'code' => 'FORBIDDEN'], 403);
        }

        $data = $request->validate([
            'organization_name' => ['required', 'string', 'min:3', 'max:150'],
            'organization_type' => ['required', Rule::in([
                'hospital', 'clinic', 'pharmacy', 'laboratory', 'home_care', 'other',
            ])],
            'registration_number' => ['required', 'string', 'min:5', 'max:80'],
            'state' => ['required', 'string', 'max:80'],
        ]);
        $profile = WorkforceEmployer::query()->firstOrNew(['account_id' => $actor->id]);
        $profile->fill($data);
        $profile->save();

        return response()->json([
            'success' => true,
            'message' => 'Organization details saved. Employer recruitment remains unavailable until independent approval.',
            'data' => ['verification_status' => $profile->verification_status],
        ]);
    }

    public function candidates(Request $request)
    {
        $actor = $this->actor($request);
        if ($actor->account_type !== 'employer'
            || ! $actor->employer
            || $actor->employer->verification_status !== 'verified'
            || $actor->email_verified_at === null) {
            return response()->json(['success' => false, 'code' => 'EMPLOYER_NOT_VERIFIED'], 403);
        }

        $filter = $request->validate([
            'state' => ['sometimes', 'string', 'max:80'],
            'desired_role' => ['sometimes', 'string', 'max:100'],
        ]);

        $candidates = WorkforceCandidate::query()
            ->with(['account:id,name'])
            ->where('verification_status', 'verified')
            ->where('available_for_work', true)
            ->where('consent_to_list', true)
            ->whereHas('account', fn ($q) => $q->where('is_active', true)
                ->whereNotNull('email_verified_at'))
            ->where(function ($q) {
                $q->where('career_stage', '!=', 'licensed')
                  ->orWhere(function ($licensed) {
                      $licensed->where('licence_status', 'verified')
                          ->whereDate('licence_expires_at', '>=', today());
                  });
            });
        if (isset($filter['state'])) {
            $candidates->where('state', $filter['state']);
        }
        if (isset($filter['desired_role'])) {
            $candidates->where('desired_role', $filter['desired_role']);
        }

        // Explicitly shape the response: never return uploaded credentials,
        // identity documents, phone/email, home location or patient records.
        $rows = $candidates->orderByDesc('updated_at')->paginate(15);
        $safe = $rows->getCollection()->map(fn ($candidate) => [
            'candidate_id' => $candidate->id,
            'display_name' => $candidate->account?->name,
            'career_stage' => $candidate->career_stage,
            'qualification' => $candidate->qualification,
            'institution' => $candidate->institution,
            'desired_role' => $candidate->desired_role,
            'state' => $candidate->state,
            'city' => $candidate->city,
            'shift_preference' => $candidate->shift_preference,
            'education_verified' => true,
            'licence_verified' => $candidate->licence_status === 'verified'
                && $candidate->licence_expires_at !== null
                && $candidate->licence_expires_at->isFuture(),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $safe,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        DB::table('workforce_sessions')
            ->where('id', $request->attributes->get('workforce_session_id'))
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        return response()->json(['success' => true]);
    }

    private function actor(Request $request): WorkforceAccount
    {
        return $request->attributes->get('workforce_actor');
    }
}
