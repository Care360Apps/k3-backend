<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SuperAdmin\CustomController;
use App\Models\Country;
use App\Models\Doctor;
use App\Models\Setting;
use App\Models\User;
use App\Services\DoctorDocumentService;
use Carbon\Carbon;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProviderController extends Controller
{
    public function providerLogin()
    {
        return view('providerAdmin.auth.login');
    }

    public function verify_provider(Request $request)
    {
        $request->validate([
            'email' => 'bail|required|email',
            'password' => 'bail|required|min:6',
        ]);

        if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            $user = Auth::user()->load('roles');
            if ($user->hasRole('provider')) {
                if ($user->verify == 1) {
                    $provider = Doctor::where('user_id', $user->id)->first();
                    if ($provider && $provider->status == 1) {
                        return redirect('provider_home');
                    }

                    Auth::logout();

                    return redirect()->back()->withErrors('You are disabled by admin. Please contact admin.');
                }

                return redirect()->back()->withErrors('Please verify your account before login.');
            }

            Auth::logout();

            return redirect()->back()->withErrors('Only providers can login here.');
        }

        Auth::logout();

        return redirect()->back()->withErrors('Your credentials do not match our records.');
    }

    public function provider_home()
    {
        $provider = Doctor::where('user_id', auth()->id())->first();
        $setting = Setting::first();

        return view('providerAdmin.home', compact('provider', 'setting'));
    }

    public function provider_signUp()
    {
        $countries = Country::get();

        return view('providerAdmin.auth.signup', compact('countries'));
    }

    public function provider_register(Request $request)
    {
        $request->validate([
            'name'            => 'bail|required',
            'phone'           => 'bail|required|digits_between:6,12',
            'email'           => 'bail|required|email|unique:users',
            'address'         => 'bail|required',
            'password'        => 'bail|required|min:6',
            'provider_type_id'=> 'bail|required|exists:provider_types,id',
        ] + DoctorDocumentService::rules(true), DoctorDocumentService::messages());

        $data = $request->except('documents');
        $password = $request->password;
        $setting = Setting::first();
        $verify = $setting->verification == 1 ? 0 : 1;

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($password),
            'verify'    => $verify,
            'phone'     => $data['phone'],
            'phone_code'=> $data['phone_code'] ?? '',
            'image'     => 'defaultUser.png',
            'status'    => 1,
        ]);
        $user->assignRole('provider');

        $data['user_id'] = $user->id;
        $data['image'] = 'defaultUser.png';
        $data['based_on'] = $setting->default_base_on;
        if ($data['based_on'] == 'commission') {
            $data['commission_amount'] = $setting->default_commission;
        }
        $data['since'] = Carbon::now(env('timezone'))->format('Y-m-d , h:i A');
        $data['status'] = 1;
        $data['name'] = $user->name;
        $data['dob'] = null;
        $data['gender'] = null;
        $data['start_time'] = '08:00 am';
        $data['end_time'] = '08:00 pm';
        $data['timeslot'] = 15;
        $data['is_filled'] = 0;
        $data['subscription_status'] = 1;
        $data['user_role'] = 'provider';

        $provider = Doctor::create($data);
        (new DoctorDocumentService)->storeMany($provider, $request->file('documents'));

        // Optionally send welcome mail / OTP via CustomController
        (new CustomController())->sendOtp($user);

        return redirect('provider_login')->with('status', 'Provider registered successfully. Please verify your account and login.');
    }
}

