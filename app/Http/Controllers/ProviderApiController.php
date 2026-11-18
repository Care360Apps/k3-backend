<?php

namespace App\Http\Controllers;

use App\Http\Controllers\SuperAdmin\CustomController;
use App\Models\Doctor;
use App\Services\DoctorDocumentService;
use App\Services\FirebaseTokenService;
use App\Models\DoctorSubscription;
use App\Models\ProviderType;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProviderApiController extends Controller
{
    /**
     * List provider types for registration dropdown.
     */
    public function apiProviderTypes()
    {
        $types = ProviderType::where('status', 1)->get(['id', 'name', 'description', 'icon']);

        return response(['success' => true, 'data' => $types, 'msg' => 'Provider types']);
    }

    /**
     * Provider registration (similar to doctor, but assigns provider role and user_role).
     */
    public function apiProviderRegister(Request $request)
    {
        $request->validate([
            'name' => 'bail|required|unique:doctor,name',
            'email' => 'bail|required|email|unique:users',
            'dob' => 'bail|required|date_format:Y-m-d',
            'gender' => 'bail|required',
            'phone' => 'bail|required|numeric',
            'password' => 'bail|required|min:6',
            'phone_code' => 'bail|required',
            'provider_type_id' => 'bail|required|exists:provider_types,id',
        ] + DoctorDocumentService::rules(false), DoctorDocumentService::messages());

        $data = $request->except('documents');
        $setting = Setting::first();
        $verificationRequires = $setting->verification;
        $isVerified = $verificationRequires == 1 ? 0 : 1;

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($request->password),
            'verify' => $isVerified,
            'phone' => $data['phone'],
            'phone_code' => $data['phone_code'],
            'image' => 'defaultUser.png',
            'status' => 1,
            'dob' => $data['dob'],
            'gender' => $data['gender'],
        ]);
        $user->assignRole('provider');

        if (isset($request->device_token)) {
            $user->device_token = $request->device_token;
            $user->save();
        }

        $data['user_id'] = $user->id;
        $data['image'] = 'defaultUser.png';
        $data['based_on'] = $setting->default_base_on;
        if ($data['based_on'] == 'commission') {
            $data['commission_amount'] = $setting->default_commission;
        }
        $data['since'] = Carbon::now(env('timezone'))->format('Y-m-d , h:i A');
        $data['status'] = 1;
        // Provider credentials require independent admin approval before access.
        $data['is_approved'] = 0;
        $data['name'] = $user->name;
        $data['dob'] = $request->dob;
        $data['gender'] = $request->gender;
        $data['start_time'] = '08:00 am';
        $data['end_time'] = '08:00 pm';
        $data['timeslot'] = 15;
        $data['is_filled'] = 0;
        $data['subscription_status'] = 1;
        $data['user_role'] = 'provider';
        $data['provider_type_id'] = $request->provider_type_id;
        $data['offers_video_call'] = 0;
        $data['offers_home_visit'] = 1;

        $doctor = Doctor::create($data);
        (new DoctorDocumentService)->storeMany($doctor, $request->file('documents') ?? []);
        $userProvider = User::find($doctor->user_id);

        if ($doctor->based_on == 'subscription') {
            $subscription = Subscription::where('name', 'free')->first();
            if ($subscription) {
                $doctor_subscription['doctor_id'] = $doctor->id;
                $doctor_subscription['subscription_id'] = $subscription->id;
                $doctor_subscription['duration'] = 1;
                $doctor_subscription['start_date'] = Carbon::now(env('timezone'))->format('Y-m-d');
                $doctor_subscription['end_date'] = Carbon::now(env('timezone'))->addMonths(1)->format('Y-m-d');
                $doctor_subscription['status'] = 1;
                $doctor_subscription['payment_status'] = 1;
                DoctorSubscription::create($doctor_subscription);
            }
        }

        $start_time = strtolower('08:00 am');
        $end_time = strtolower('08:00 pm');
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        for ($i = 0; $i < count($days); $i++) {
            $master = [];
            $temp2['start_time'] = $start_time;
            $temp2['end_time'] = $end_time;
            array_push($master, $temp2);
            $work_time['doctor_id'] = $doctor->id;
            $work_time['period_list'] = json_encode($master);
            $work_time['day_index'] = $days[$i];
            $work_time['status'] = 1;
            WorkingHour::create($work_time);
        }

        if ($user->verify == 1) {
            if (Auth::attempt(['email' => $user['email'], 'password' => $request->password])) {
                $userProvider['token'] = $user->createToken('doctro')->accessToken;
                $userProvider['is_filled'] = $doctor->is_filled;
                $userProvider['user_role'] = 'provider';
                $userProvider['approval_status'] = 'pending';
                // Do not issue Firebase chat credentials before professional verification.
                $userProvider['firebase_custom_token'] = null;

                return response(['success' => true, 'data' => $userProvider, 'msg' => 'Register Successfully']);
            }
        } else {
            (new CustomController)->sendOtp($user);
            $user['is_filled'] = $doctor->is_filled;
            $user['user_role'] = 'provider';

            return response(['success' => false, 'data' => $user, 'msg' => 'otp send into your account please verify']);
        }
    }

    /**
     * Provider login (providers use doctor app; allow both doctor and provider roles).
     */
    public function apiProviderLogin(Request $request)
    {
        $request->validate([
            'email' => 'bail|required|email',
            'password' => 'bail|required|min:6',
        ]);

        if (Auth::attempt(['email' => request('email'), 'password' => request('password')])) {
            $user = Auth::user()->load('roles');
            if ($user->hasRole('provider')) {
                $doctor = Doctor::where('user_id', auth()->user()->id)->first();
                if (! $doctor || $doctor->user_role !== 'provider') {
                    return response(['success' => false, 'msg' => 'Provider profile not found.'], 403);
                }
                if ((int) $user->status !== 1 || (int) $doctor->status !== 1) {
                    return response(['success' => false, 'msg' => 'You are blocked please contact to admin.']);
                }
                if ($user->verify != 1) {
                    (new CustomController)->sendOtp($user);
                    $user->makeHidden(['email_verified_at', 'dob', 'gender', 'status', 'doctor_id']);

                    return response(['success' => false, 'data' => $user, 'msg' => 'otp send into your account please verify']);
                }

                if (isset($request->device_token)) {
                    $user->device_token = $request->device_token;
                    $user->save();
                }

                $user['token'] = $user->createToken('doctro')->accessToken;
                $user['image'] = $doctor->fullImage;
                $user['is_filled'] = $doctor->is_filled;
                $user['user_role'] = 'provider';
                $user['approval_status'] = (int) $doctor->is_approved === 1 ? 'approved' : 'pending';
                $user->makeHidden(['email_verified_at', 'dob', 'gender', 'status', 'doctor_id']);
                $user['device_token'] = $user['device_token'] == null ? '' : $user['device_token'];
                $user['language'] = $user['language'] == null ? '' : $user['language'];
                $user['channel_name'] = $user['channel_name'] == null ? '' : $user['channel_name'];
                $user['agora_token'] = $user['agora_token'] == null ? '' : $user['agora_token'];
                $user['firebase_custom_token'] = (int) $doctor->is_approved === 1
                    ? (new FirebaseTokenService())->providerToken($user->id)
                    : null;

                return response(['success' => true, 'data' => $user, 'msg' => 'Provider Login successfully']);
            }
        }

        return response(['success' => false, 'msg' => 'Invalid Email or Password!.']);
    }
}
