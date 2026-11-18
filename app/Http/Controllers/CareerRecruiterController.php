<?php

namespace App\Http\Controllers;

use App\Models\WorkforceAccount;
use App\Services\CareerWorkflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CareerRecruiterController extends Controller
{
    public function index(Request $r)
    {
        $a = W::actor($r);
        abort_unless($a->account_type === 'employer', 403);
        $a->load('employer');
        $owner = $a->email_verified_at && $a->employer?->verification_status === 'verified';
        $q = DB::table('career_recruiters as r')->join('workforce_accounts as a', 'a.id', '=', 'r.recruiter_account_id')
            ->join('workforce_employers as e', 'e.account_id', '=', 'r.owner_account_id');
        if ($owner) {
            $q->where('r.owner_account_id', $a->id);
        } else {
            $q->where('r.recruiter_account_id', $a->id);
        }
        $rows = $q->orderByDesc('r.id')->paginate(20, ['r.id', 'r.status', 'r.accepted_at', 'a.name', 'e.organization_name']);

        return W::response(['can_invite' => (bool) $owner, 'records' => $rows]);
    }

    public function invite(Request $r)
    {
        $a = W::owner(W::actor($r));
        $d = $r->validate(['email' => 'required|email|max:190', 'staff_authority_attestation' => 'accepted']);
        $staff = WorkforceAccount::where('email', strtolower(trim($d['email'])))->where('account_type', 'employer')->where('is_active', true)->first();
        abort_unless($staff && $staff->id !== $a->id, 404);
        $staff->load('employer');
        abort_if($staff->employer?->verification_status === 'verified', 422);
        $id = DB::transaction(function () use ($a, $staff) {
            $old = DB::table('career_recruiters')->where('recruiter_account_id', $staff->id)->lockForUpdate()->first();
            if ($old && in_array($old->status, ['pending_review', 'approved', 'accepted'], true)) {
                abort_unless((int) $old->owner_account_id === $a->id, 409);

                return $old->id;
            }
            abort_if(DB::table('career_recruiters')->where('owner_account_id', $a->id)->whereIn('status', ['pending_review', 'approved', 'accepted'])->count() >= 50, 422);
            $values = ['owner_account_id' => $a->id, 'recruiter_account_id' => $staff->id, 'status' => 'pending_review',
                'evidence_sha256' => null, 'reviewer' => null, 'accepted_at' => null, 'updated_at' => now()];
            if ($old) {
                DB::table('career_recruiters')->where('id', $old->id)->update($values);
                $id = $old->id;
            } else {
                $id = DB::table('career_recruiters')->insertGetId($values + ['created_at' => now()]);
            }
            W::event('recruiter', $id, 'workforce.'.$a->id, 'invited_for_review');
            W::notify($staff->id, 'recruiter_invitation', $id);

            return $id;
        });

        return W::response(['id' => $id, 'status' => 'pending_review'], 201);
    }

    public function respond(Request $r, int $id)
    {
        $a = W::actor($r);
        $d = $r->validate(['status' => ['required', Rule::in(['accepted', 'declined', 'revoked'])]]);
        DB::transaction(function () use ($a, $d, $id) {
            $x = DB::table('career_recruiters')->where('id', $id)->lockForUpdate()->first();
            abort_unless($x, 404);
            if ($d['status'] === 'revoked') {
                W::owner($a);
                abort_unless((int) $x->owner_account_id === $a->id, 404);
                abort_unless(in_array($x->status, ['pending_review', 'approved', 'accepted'], true), 409);
            } else {
                abort_unless((int) $x->recruiter_account_id === $a->id, 404);
                if ($d['status'] === 'accepted') {
                    abort_unless($x->status === 'approved' && $a->email_verified_at, 409);
                    W::owner(WorkforceAccount::findOrFail($x->owner_account_id));
                } else {
                    abort_unless(in_array($x->status, ['pending_review', 'approved', 'accepted'], true), 409);
                }
            }
            DB::table('career_recruiters')->where('id', $id)->update(['status' => $d['status'],
                'accepted_at' => $d['status'] === 'accepted' ? now() : null, 'updated_at' => now()]);
            W::event('recruiter', $id, 'workforce.'.$a->id, $d['status']);
            W::notify($a->id === (int) $x->owner_account_id ? $x->recruiter_account_id : $x->owner_account_id, 'recruiter_status', $id);
        });

        return W::response(['recorded' => true]);
    }
}
