<?php

namespace App\Http\Controllers;

use App\Mail\CareerPasswordReset;
use App\Models\WorkforceAccount;
use App\Services\CareerWorkflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class CareerPasswordController extends Controller
{
    public function request(Request $r)
    {
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('career_password_resets'), 503);
        $d = $r->validate(['email' => 'required|email|max:190']);
        $a = WorkforceAccount::where('email', strtolower(trim($d['email'])))->where('is_active', true)->first();
        if ($a) {
            $code = bin2hex(random_bytes(32));
            $id = DB::transaction(function () use ($a, $code) {
                DB::table('career_password_resets')->where('account_id', $a->id)->whereNull('redeemed_at')->update(['redeemed_at' => now(), 'updated_at' => now()]);

                return DB::table('career_password_resets')->insertGetId(['account_id' => $a->id, 'token_hash' => hash('sha256', $code),
                    'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
            });
            try {
                Mail::to($a->email)->send(new CareerPasswordReset($code));
            } catch (\Throwable $e) {
                DB::table('career_password_resets')->where('id', $id)->update(['redeemed_at' => now(), 'updated_at' => now()]);
                logger()->warning('Career recovery delivery unavailable.');
            }
        }

        return W::response(['message' => 'If an active account exists, recovery instructions will be sent to its email address.'], 202);
    }

    public function complete(Request $r)
    {
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('career_password_resets'), 503);
        $d = $r->validate(['email' => 'required|email|max:190', 'code' => 'required|regex:/^[a-f0-9]{64}$/',
            'password' => 'required|string|min:12|max:128|confirmed']);
        DB::transaction(function () use ($d) {
            $a = WorkforceAccount::where('email', strtolower(trim($d['email'])))->where('is_active', true)->first();
            abort_unless($a, 422);
            $reset = DB::table('career_password_resets')->where('account_id', $a->id)->where('token_hash', hash('sha256', $d['code']))
                ->whereNull('redeemed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($reset, 422);
            DB::table('workforce_accounts')->where('id', $a->id)->update(['password' => Hash::make($d['password']), 'updated_at' => now()]);
            DB::table('career_password_resets')->where('account_id', $a->id)->whereNull('redeemed_at')->update(['redeemed_at' => now(), 'updated_at' => now()]);
            DB::table('workforce_sessions')->where('account_id', $a->id)->delete();
            W::event('career_account', $a->id, 'recovery.'.$a->id, 'password_recovered');
        });

        return W::response(['message' => 'Career password updated. Sign in again on each device.']);
    }
}
