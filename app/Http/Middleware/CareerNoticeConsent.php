<?php

namespace App\Http\Middleware;

use App\Services\RecruitmentNotice;
use Closure;
use Illuminate\Http\Request;

class CareerNoticeConsent
{
    public function handle(Request $r, Closure $next)
    {
        $actor = $r->attributes->get('workforce_actor');
        if ($actor && \Illuminate\Support\Facades\Schema::hasTable('career_privacy_requests') && \Illuminate\Support\Facades\DB::table('career_privacy_requests')->where('account_id', $actor->id)->whereIn('status', ['requested', 'processing'])->exists()) {
            return response()->json(['success' => false, 'code' => 'ACCOUNT_CLOSURE_PENDING', 'message' => 'Career account closure is pending review. Your private export remains available.'], 409);
        }
        $notice = RecruitmentNotice::details($r);
        $actor = $r->attributes->get('workforce_actor');
        if (! $notice || ! $actor || $actor->recruitment_notice_version !== $notice['version'] || ! $actor->recruitment_notice_accepted_at) {
            return response()->json(['success' => false, 'code' => 'CONSENT_REQUIRED',
                'message' => 'Review and accept the current recruitment and training privacy notice before continuing.'], 409);
        }

        return $next($r);
    }
}
