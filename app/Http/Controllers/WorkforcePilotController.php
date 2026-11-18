<?php

namespace App\Http\Controllers;

use App\Models\WorkforceAccount;
use App\Models\WorkforceCandidate;
use App\Models\WorkforceEmployer;
use App\Support\WorkforceRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WorkforcePilotController extends Controller
{
    public function status(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'applications_open' => (bool) config('workforce.enabled', false) && \App\Services\RecruitmentNotice::details($request)!==null,
                'notice'=>\App\Services\RecruitmentNotice::details($request),
                'candidate_verification' => 'manual_review',
                'employer_verification' => 'manual_review',
            ],
        ]);
    }

    public function register(Request $request)
    {
        $notice=\App\Services\RecruitmentNotice::details($request);
        abort_unless($notice,503);
        $request->validate(['notice_version'=>['required',Rule::in([$notice['version']])]]);
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email', ''))),
        ]);
        if (config('workforce.local_demo', false)) {
            abort_unless(app()->environment('local','testing') && in_array($request->ip(), ['127.0.0.1','::1'],true),403);
            $request->validate(['email'=>['required','regex:/^[a-zA-Z0-9._+-]+@care360\.example\.test$/']]);
        }
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
        DB::transaction(function () use ($data, $notice) {
            $actor = WorkforceAccount::create([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'account_type' => $data['account_type'],
            ]);
            $actor->forceFill(['recruitment_notice_version'=>$notice['version'],'recruitment_notice_accepted_at'=>now()])->save();
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
        ])->header('Cache-Control','private, no-store');
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
                'notice_version'=>$actor->recruitment_notice_version,
                'notice_current'=>($notice=\App\Services\RecruitmentNotice::details($request)) && $actor->recruitment_notice_version===$notice['version'] && $actor->recruitment_notice_accepted_at!==null,
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
            'desired_role' => ['required', Rule::in(array_keys(WorkforceRoles::ROLES))],
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
        // A changed course, role, training institution or professional scope
        // invalidates the old evidence. Mere availability/consent toggles do
        // not, but can hide a profile immediately.
        $materialFields = [
            'career_stage', 'institution', 'qualification', 'desired_role', 'state', 'city',
        ];
        if ($profile->isDirty($materialFields)) {
            $profile->verification_status = 'pending';
            $profile->licence_status = 'not_verified';
            $profile->licence_expires_at = null;
        }
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
        // Employers must be reverified whenever their legal identity,
        // registration number or facility category changes.
        if ($profile->isDirty([
            'organization_name', 'organization_type', 'registration_number', 'state',
        ])) {
            $profile->verification_status = 'pending';
        }
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
        try { \App\Services\CareerWorkflow::employer($actor); } catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if($e->getStatusCode()!==403)throw $e;
            return response()->json(['success'=>false,'code'=>'EMPLOYER_NOT_VERIFIED'],403);
        }

        $filter = $request->validate([
            'state' => ['sometimes', 'string', 'max:80'],
            'desired_role' => ['sometimes', 'string', 'max:100'],
            'qualification'=>['sometimes','string','max:150'],'min_experience_years'=>['sometimes','integer','min:0','max:70'],
        ]);

        $candidates = WorkforceCandidate::query()
            ->with(['account:id,name'])
            ->where('verification_status', 'verified')
            ->where('available_for_work', true)
            ->where('consent_to_list', true)
            ->whereHas('account', fn ($q) => $q->where('is_active', true)
                ->whereNotNull('email_verified_at'))
            ->where(function ($q) {
                // A graduate cannot list as a nurse/doctor/pharmacist or other
                // regulated practitioner simply by choosing the role label.
                $q->where(function ($support) {
                    $support->whereNotIn('desired_role',
                        WorkforceRoles::licenceRequired())
                        ->where('career_stage', '!=', 'licensed');
                })->orWhere(function ($regulated) {
                    $regulated->where('licence_status', 'verified')
                        ->whereDate('licence_expires_at', '>=', today());
                });
            });
        if (isset($filter['state'])) {
            $candidates->where('state', $filter['state']);
        }
        if (isset($filter['desired_role'])) {
            $candidates->where('desired_role', $filter['desired_role']);
        }

        if(!empty($filter['qualification'])) $candidates->where('qualification','like','%'.str_replace(['%','_'],['\\%','\\_'],$filter['qualification']).'%');
        if(isset($filter['min_experience_years'])) $candidates->whereExists(function($q)use($filter){
            $q->selectRaw('1')->from('career_portfolios')->whereColumn('career_portfolios.account_id','workforce_candidates.account_id')
                ->where('experience_years','>=',$filter['min_experience_years']);
        });

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
                && $candidate->licence_expires_at->greaterThanOrEqualTo(today()),
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

    public function consent(Request $request)
    {
        $a=$this->actor($request);$notice=\App\Services\RecruitmentNotice::details($request);abort_unless($notice,503);
        $request->validate(['accepted'=>'accepted','notice_version'=>['required',Rule::in([$notice['version']])]]);
        $a->forceFill(['recruitment_notice_version'=>$notice['version'],'recruitment_notice_accepted_at'=>now()])->save();
        return response()->json(['success'=>true,'data'=>['notice_current'=>true]]);
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
