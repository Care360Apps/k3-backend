<?php

namespace App\Http\Controllers;

use App\Mail\SendMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Password resets for every role: an expiring, single-use link is emailed; the password only changes when the
 * account owner follows it. Callers must respond the same way whether or not the email is registered.
 */
class PasswordResetController extends Controller
{
    public const GENERIC_MESSAGE = 'If an account exists for that email, a password reset link has been sent to it.';

    public static function sendLink(?string $email): void
    {
        $user = $email ? User::where('email', $email)->first() : null;
        if (! $user) {
            return;
        }
        $broker = Password::broker();
        if ($broker->getRepository()->recentlyCreatedToken($user)) {
            return;
        }
        $token = $broker->createToken($user);
        $link = url('reset-password/'.$token).'?email='.urlencode($user->email);
        $setting = Setting::first();
        $minutes = (int) config('auth.passwords.users.expire', 60);
        $content = '<p>'.e(__('Hello :name,', ['name' => $user->name])).'</p>'
            .'<p>'.e(__('We received a request to reset your :app password. Use the link below within :minutes minutes:', ['app' => $setting->business_name, 'minutes' => $minutes])).'</p>'
            .'<p><a href="'.e($link).'">'.e($link).'</a></p>'
            .'<p>'.e(__('If you did not ask for this, ignore this email; your password stays the same.')).'</p>';

        try {
            Config::set('mail', [
                'driver' => $setting->mail_mailer,
                'host' => $setting->mail_host,
                'port' => $setting->mail_port,
                'from' => ['address' => $setting->mail_from_address, 'name' => $setting->mail_from_name],
                'encryption' => $setting->mail_encryption,
                'username' => $setting->mail_username,
                'password' => $setting->mail_password,
            ]);
            Mail::to($user->email)->send(new SendMail($content, __('Reset your password')));
        } catch (\Exception $e) {
            // SMTP exception bodies can contain recipient addresses and mail
            // configuration. Never log them in a healthcare application.
            Log::warning('Care360 password-reset notification delivery failed');
        }
    }

    public function showResetForm(Request $request, string $token)
    {
        return view('auth.reset_password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'bail|required|string',
            'email' => 'bail|required|email',
            'password' => 'bail|required|min:8|confirmed',
        ]);
        $user = User::where('email', $request->email)->first();
        $broker = Password::broker();
        if (! $user || ! $broker->tokenExists($user, $request->token)) {
            return back()->withErrors(['email' => __('This password reset link is invalid or has expired.')]);
        }
        $user->forceFill(['password' => Hash::make($request->password)])->save();
        $broker->deleteToken($user);
        // Sign the account out everywhere: API tokens and remembered web sessions.
        DB::table('oauth_access_tokens')->where('user_id', $user->id)->update(['revoked' => true]);
        $user->setRememberToken(null);
        $user->save();

        return view('auth.reset_password', ['done' => true]);
    }
}
