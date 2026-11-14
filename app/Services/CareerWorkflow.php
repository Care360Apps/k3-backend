<?php

namespace App\Services;

use App\Models\WorkforceAccount;
use App\Models\WorkforceCandidate;
use App\Support\WorkforceRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class CareerWorkflow
{
    public static function actor(Request $request): WorkforceAccount
    {
        $actor = $request->attributes->get('workforce_actor');
        abort_unless($actor instanceof WorkforceAccount && $actor->is_active, 401);

        return $actor;
    }

    public static function owner(WorkforceAccount $actor): WorkforceAccount
    {
        $actor->refresh()->load('employer');
        abort_unless($actor->is_active && $actor->account_type === 'employer' && $actor->email_verified_at
            && $actor->employer?->verification_status === 'verified', 403);

        return $actor;
    }

    public static function employer(WorkforceAccount $actor): WorkforceAccount
    {
        $actor->refresh()->load('employer');
        abort_unless($actor->is_active && $actor->account_type === 'employer' && $actor->email_verified_at, 403);
        if ($actor->employer?->verification_status === 'verified') {
            return $actor;
        }
        abort_unless(config('career.enabled', false), 403);
        $membership = DB::table('career_recruiters')->where('recruiter_account_id', $actor->id)->where('status', 'accepted')->first();
        abort_unless($membership, 403);

        return self::owner(WorkforceAccount::findOrFail($membership->owner_account_id));
    }

    public static function eligible(WorkforceAccount $actor): WorkforceCandidate
    {
        if (config('career.enabled', false)) {
            abort_if(DB::table('career_privacy_requests')->where('account_id', $actor->id)->whereIn('status', ['requested', 'processing'])->exists(), 403);
        }
        $actor->load('candidate');
        $p = $actor->candidate;
        abort_unless($actor->account_type === 'candidate' && $actor->email_verified_at
            && $p?->verification_status === 'verified', 403);
        if ($p->career_stage === 'licensed' || in_array($p->desired_role, WorkforceRoles::licenceRequired(), true)) {
            abort_unless($p->licence_status === 'verified' && $p->licence_expires_at
                && $p->licence_expires_at->greaterThanOrEqualTo(today()), 403);
        }

        return $p;
    }

    public static function pack(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function unpack(string $data): array
    {
        return json_decode(Crypt::decryptString($data), true, 64, JSON_THROW_ON_ERROR);
    }

    public static function event(string $type, int $id, string $actor, string $action, array $details = []): void
    {
        DB::table('career_workflow_events')->insert(['subject_type' => $type, 'subject_id' => $id,
            'actor' => $actor, 'action' => $action, 'details' => self::pack($details), 'created_at' => now()]);
    }

    public static function notify(int $account, string $kind, int $resource): void
    {
        DB::table('career_notifications')->insert(['account_id' => $account, 'kind' => $kind,
            'resource_id' => $resource, 'created_at' => now(), 'updated_at' => now()]);
        if (in_array($kind, ['application_received', 'application_status', 'offer_response', 'invitation_status', 'recruitment_message'], true)) {
            $staff = DB::table('career_recruiters as r')->join('workforce_accounts as a', 'a.id', '=', 'r.recruiter_account_id')->where('r.owner_account_id', $account)->where('r.status', 'accepted')->where('a.is_active', true)->limit(50)->pluck('r.recruiter_account_id');
            foreach ($staff as $id) {
                DB::table('career_notifications')->insert(['account_id' => $id, 'kind' => $kind, 'resource_id' => $resource, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public static function response($data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
