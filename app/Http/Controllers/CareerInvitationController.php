<?php

namespace App\Http\Controllers;

use App\Models\WorkforceAccount;
use App\Models\WorkforceCandidate;
use App\Services\CareerWorkflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CareerInvitationController extends Controller
{
    private function invitation(Request $r, int $id, bool $lock = false): object
    {
        $a = W::actor($r);
        $q = DB::table('workforce_invitations')->where('id', $id);
        if ($lock) {
            $q->lockForUpdate();
        }$i = $q->first();
        abort_unless($i, 404);
        if ($a->account_type === 'employer') {
            $org = W::employer($a);
            abort_unless((int) $i->employer_account_id === $org->id, 404);
        } else {
            abort_unless($a->id === (int) $i->candidate_account_id, 404);
        }

        return $i;
    }

    public function index(Request $r)
    {
        $a = W::actor($r);
        $scope = $a->account_type === 'employer' ? W::employer($a)->id : $a->id;

        return W::response(DB::table('workforce_invitations')->where($a->account_type === 'candidate' ? 'candidate_account_id' : 'employer_account_id', $scope)
            ->orderByDesc('id')->paginate(20, ['id', 'job_id', 'role_title', 'job_summary', 'status', 'expires_at']));
    }

    public function invite(Request $r)
    {
        $a = W::actor($r);
        $org = W::employer($a);
        $d = $r->validate(['job_id' => 'required|integer|min:1', 'candidate_id' => 'required|integer|min:1']);
        $result = DB::transaction(function () use ($a, $org, $d) {
            $j = DB::table('career_jobs')->where('id', $d['job_id'])->where('employer_account_id', $org->id)->lockForUpdate()->first();
            abort_unless($j && $j->status === 'published' && $j->closes_on >= today()->toDateString(), 404);
            $p = WorkforceCandidate::findOrFail($d['candidate_id']);
            abort_unless($p->consent_to_list && $p->available_for_work && $p->desired_role === $j->profession, 404);
            W::eligible(WorkforceAccount::findOrFail($p->account_id));
            $old = DB::table('workforce_invitations')->where('job_id', $j->id)->where('candidate_account_id', $p->account_id)
                ->whereIn('status', ['pending', 'accepted'])->where('expires_at', '>', now())->first();
            if ($old) {
                return ['id' => $old->id, 'status' => $old->status];
            }
            $id = DB::table('workforce_invitations')->insertGetId(['job_id' => $j->id, 'employer_account_id' => $org->id,
                'candidate_account_id' => $p->account_id, 'role_title' => $j->title, 'job_summary' => mb_substr($j->description, 0, 1500),
                'status' => 'pending', 'expires_at' => now()->addDays(14), 'created_at' => now(), 'updated_at' => now()]);
            W::event('invitation', $id, 'workforce.'.$a->id, 'sent');
            W::notify($p->account_id, 'recruitment_invitation', $id);

            return ['id' => $id, 'status' => 'pending'];
        });

        return W::response($result, 201);
    }

    public function respond(Request $r, int $id)
    {
        $a = W::actor($r);
        $d = $r->validate(['status' => ['required', Rule::in(['accepted', 'declined', 'closed'])]]);
        DB::transaction(function () use ($r, $a, $d, $id) {
            $i = $this->invitation($r, $id, true);
            if ($d['status'] === 'closed') {
                abort_unless($i->status === 'accepted', 409);
            } else {
                abort_unless($a->account_type === 'candidate' && $i->status === 'pending' && \Carbon\Carbon::parse($i->expires_at)->isFuture(), 409);
            }
            if ($d['status'] === 'accepted') {
                W::owner(WorkforceAccount::findOrFail($i->employer_account_id));
            }
            DB::table('workforce_invitations')->where('id', $id)->update(['status' => $d['status'], 'updated_at' => now()]);
            W::event('invitation', $id, 'workforce.'.$a->id, $d['status']);
            W::notify($a->id === (int) $i->candidate_account_id ? $i->employer_account_id : $i->candidate_account_id, 'invitation_status', $id);
        });

        return W::response(['recorded' => true]);
    }

    public function messages(Request $r, int $id)
    {
        $i = $this->invitation($r, $id);
        abort_unless(in_array($i->status, ['accepted', 'closed'], true), 403);
        $rows = DB::table('workforce_messages')->where('invitation_id', $id)->orderByDesc('id')->paginate(30);
        $rows->setCollection($rows->getCollection()->map(fn ($x) => ['id' => $x->id, 'sender_account_id' => $x->sender_account_id,
            'body' => W::unpack($x->body_encrypted)['body'], 'created_at' => $x->created_at]));

        return W::response($rows);
    }

    public function sendMessage(Request $r, int $id)
    {
        $a = W::actor($r);
        $d = $r->validate(['body' => 'required|string|min:1|max:4000']);
        DB::transaction(function () use ($r, $a, $d, $id) {
            $i = $this->invitation($r, $id, true);
            abort_unless($i->status === 'accepted', 409);
            W::owner(WorkforceAccount::findOrFail($i->employer_account_id));
            DB::table('workforce_messages')->insert(['invitation_id' => $id, 'sender_account_id' => $a->id,
                'body_encrypted' => W::pack(['body' => $d['body']]), 'created_at' => now(), 'updated_at' => now()]);
            W::event('invitation', $id, 'workforce.'.$a->id, 'message_sent');
            W::notify($a->id === (int) $i->candidate_account_id ? $i->employer_account_id : $i->candidate_account_id, 'recruitment_message', $id);
        });

        return W::response(['sent' => true], 201);
    }

    public function report(Request $r, int $id)
    {
        $a = W::actor($r);
        $this->invitation($r, $id);
        $d = $r->validate(['reason' => ['required', Rule::in(['scam', 'harassment', 'unauthorized_data', 'other'])]]);
        DB::transaction(function () use ($a, $d, $id) {
            DB::table('workforce_reports')->updateOrInsert(['invitation_id' => $id, 'reporter_account_id' => $a->id],
                ['reason' => $d['reason'], 'status' => 'open', 'created_at' => now()]);
            W::event('invitation', $id, 'workforce.'.$a->id, 'reported', ['reason' => $d['reason']]);
        });

        return W::response(['reported' => true]);
    }
}
