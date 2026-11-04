<?php

namespace App\Http\Controllers;

use App\Services\AccountErasureService;
use App\Services\ConsultationCapabilities;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SuperAdmin\CustomController;
use App\Mail\SendMail;
use App\Services\FirebaseTokenService;
use App\Services\MedicineOrderPricing;
use App\Services\OtpService;
use App\Services\PaymentGatewayService;
use App\Models\Appointment;
use App\Models\Banner;
use App\Models\Blog;
use App\Models\Course;
use App\Models\Doctor;
use App\Models\Faviroute;
use App\Models\Hospital;
use App\Models\HospitalGallery;
use App\Models\Insurer;
use App\Models\Medicine;
use App\Models\MedicineChild;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Offer;
use App\Models\Lab;
use App\Models\LabSettle;
use App\Models\Pathology;
use App\Models\Pharmacy;
use App\Models\PharmacySettle;
use App\Models\Prescription;
use App\Models\PurchaseMedicine;
use App\Models\Radiology;
use App\Models\Report;
use App\Models\Review;
use App\Models\Settle;
use App\Models\Setting;
use App\Models\ProviderType;
use App\Models\Treatments;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\VideoCallHistory;
use Berkayk\OneSignal\OneSignalClient;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use OneSignal;
use Spatie\Permission\Models\Role;
require_once 'RtcTokenBuilder.php';

class UserApiController extends Controller
{
    public function apiLogin(Request $request)
    {
        $request->validate([
            'email' => 'bail|required|email',
            'password' => 'bail|required|min:6',
        ]);

        $user = ([
            'email' => $request->email,
            'password' => $request->password,
        ]);

        if (Auth::attempt($user)) {
            $user = Auth::user();
            if (! $user->hasAnyRole(Role::all())) {
                if ($user->status == 1) {
                    if (isset($request->device_token)) {
                        $user->device_token = $request->device_token;
                        $user->save();
                    }
                    if ($user['verify'] == 1) {
                        $user['token'] = $user->createToken('doctro')->accessToken;
                        $user->makeHidden('roles');

                        $firebase = new FirebaseTokenService();
                        $user['firebase_custom_token'] = $firebase->patientToken($user->id);

                        return response()->json(['success' => true, 'data' => $user, 'msg' => 'successfully login'], 200);
                    } else {
                        (new CustomController)->sendOtp($user);

                        return response(['success' => true, 'data' => $user, 'msg' => 'Otp send in your account']);
                    }
                } else {
                    return response(['success' => false, 'msg' => 'You are blocked please contact to admin.']);
                }
            } else {
                return response()->json(['success' => false, 'msg' => 'Only Patient Can login'], 401);
            }
        } else {
            return response()->json(['success' => false, 'msg' => 'Invalid Email & Password.']);
        }
    }

    // Logout
    public function apiLogout(Request $request)
    {
        $user = Auth::user();
        $user->update(['device_token' => null]);
        $all_devices = request()->query('all_devices', '0');
        if ($all_devices == 1) {
            $user->tokens()->delete();
        } else {
            $user->token()->delete();
        }

        return response()->json(['success' => true, 'msg' => 'Successfully logged out']);
    }

    public function apiRefreshToken(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'msg' => 'Unauthenticated'], 401);
        }
        $user['token'] = $user->createToken('doctro')->accessToken;
        $user->makeHidden('roles');

        return response()->json(['success' => true, 'data' => $user, 'msg' => 'token refreshed'], 200);
    }

    // Register
    public function apiRegister(Request $request)
    {
        $request->validate([
            'name' => 'bail|required',
            'email' => 'bail|required|email|unique:users',
            'dob' => 'bail|required|date_format:Y-m-d',
            'gender' => 'bail|required',
            'phone' => 'bail|required',
            'phone_code' => 'bail|required',
            'password' => 'bail|required|min:6',
        ]);
        $data = $request->except(['otp', 'doctor_id', 'verify', 'status', 'channel_name', 'agora_token']);
        $verification = Setting::first()->verification;
        $verify = $verification == 1 ? 0 : 1;
        $data['password'] = Hash::make($request->password);
        $data['verify'] = $verify;
        $data['image'] = 'defaultUser.png';
        $data['status'] = 1;
        $user = User::create($data);
        if ($user->verify == 1) {
            if (Auth::attempt(['email' => $user['email'], 'password' => $request->password])) {
                $user['token'] = $user->createToken('doctro')->accessToken;

                $firebase = new FirebaseTokenService();
                $user['firebase_custom_token'] = $firebase->patientToken($user->id);

                return response()->json(['success' => true, 'data' => $user, 'msg' => 'successfully register'], 200);
            }
        } else {
            return (new CustomController)->sendOtp($user);

            return response()->json(['success' => false, 'data' => $user, 'msg' => 'OTP send into your account'], 200);
        }
    }

    public function apiCheckOtp(Request $request)
    {
        $request->validate([
            'user_id' => 'bail|required|integer',
            'otp' => 'bail|required|string',
        ]);
        $user = User::find($request->user_id);
        if (! $user || ! (new OtpService)->verify($user, $request->otp)) {
            return response(['success' => false, 'msg' => 'Please Enter Valid Otp.']);
        }
        $user['token'] = $user->createToken('doctro')->accessToken;
        if (! $user->roles()->exists()) {
            $user['firebase_custom_token'] = (new FirebaseTokenService())->patientToken($user->id);
        } elseif ($user->hasAnyRole(['doctor', 'provider'])) {
            $user['firebase_custom_token'] = (new FirebaseTokenService())->doctorToken($user->id);
        }
        $user->makeHidden('roles');

        return response(['success' => true, 'data' => $user, 'msg' => 'SuccessFully verify your account...!!']);
    }

    public function apiResendOtp($user_id)
    {
        $user = User::find($user_id);
        if ($user && (int) $user->verify !== 1) {
            (new CustomController)->sendOtp($user);
        }

        // Same answer whether or not the account exists or needs a code, so this cannot be used to probe accounts.
        return response()->json(['success' => true, 'msg' => 'If the account still needs verification, a new code has been sent.']);
    }

    // Treatment Wise Doctor
    public function apiTreatmentDoctor(Request $request, $treatment_id)
    {
        $request->validate([
            'lat' => 'bail|required',
            'lang' => 'bail|required',
        ]);
        $doctor = Doctor::with('treatment:id,name')
            ->whereStatus(1)
            ->where('is_approved', 1)
            ->where([['is_filled', 1], ['treatment_id', $treatment_id]])
            ->whereSubscriptionStatus(1)
            ->where(function ($q) {
                $q->whereNull('user_role')->orWhere('user_role', 'doctor');
            })
            ->get(['id', 'status', 'image', 'name', 'treatment_id', 'hospital_id', 'rate', 'review']);
        $data = $request->all();
        $doctors = $this->getNearDoctor($doctor, $data['lat'], $data['lang']);
        foreach ($doctors as $doctor) {
            $doctor->is_faviroute = $this->checkFavourite($doctor->id);
            unset($doctor->hospital_id);
        }

        return response(['success' => true, 'data' => $doctors]);
    }

    public function apiDoctors(Request $request)
    {
        $request->validate([
            'lat' => 'bail|required',
            'lang' => 'bail|required',
        ]);
        $doctor = Doctor::with('treatment:id,name')
            ->whereStatus(1)
            ->where('is_approved', 1)
            ->where('is_filled', 1)
            ->whereSubscriptionStatus(1)
            ->where(function ($q) {
                $q->whereNull('user_role')->orWhere('user_role', 'doctor');
            })
            ->get(['id', 'status', 'image', 'name', 'treatment_id', 'hospital_id', 'address', 'lat', 'lng', 'rate', 'review']);
        $data = $request->all();
        $doctors = $this->getNearDoctor($doctor, $data['lat'], $data['lang']);
        foreach ($doctors as $doctor) {
            $doctor->is_faviroute = $this->checkFavourite($doctor->id);
            unset($doctor->hospital_id);
        }

        return response(['success' => true, 'data' => $doctors, 'msg' => 'All Doctors']);
    }

    public function apiTreatments()
    {
        $treatments = Treatments::whereStatus(1)->get(['id', 'name', 'image']);

        return response(['success' => true, 'data' => $treatments, 'msg' => 'All Treatments']);
    }

    public function apiOffers()
    {
        $offers = Offer::whereStatus(1)->get(['id', 'name', 'image', 'offer_code', 'discount', 'is_flat', 'discount_type', 'flatDiscount']);

        return response(['success' => true, 'data' => $offers, 'msg' => 'All Offers']);
    }

    public function apiCourses()
    {
        $courses = Course::orderBy('id', 'DESC')->get(['id', 'title', 'summary', 'details', 'image', 'duration', 'amount', 'link']);

        return response(['success' => true, 'data' => $courses, 'msg' => 'All Courses']);
    }

    public function apiNearByDoctor(Request $request)
    {
        $request->validate([
            'lat' => 'bail|required',
            'lang' => 'bail|required',
        ]);
        $data = $request->all();
        if (isset($data['lat']) && isset($data['lang'])) {
            $doctor = Doctor::whereStatus(1)
                ->where('is_approved', 1)
                ->where('is_filled', 1)
                ->whereSubscriptionStatus(1)
                ->where(function ($q) {
                    $q->whereNull('user_role')->orWhere('user_role', 'doctor');
                })
                ->get(['id', 'name', 'image', 'hospital_id'])
                ->makeHidden(['rate', 'review']);
            $doctors = $this->getNearDoctor($doctor, $data['lat'], $data['lang']);

            return response(['success' => true, 'data' => $doctors, 'msg' => 'show all doctors']);
        }
    }

    /**
     * List providers for patient app (home page section, limit 8).
     */
    public function apiProviders(Request $request)
    {
        $request->validate([
            'lat' => 'bail|nullable|numeric',
            'lang' => 'bail|nullable|numeric',
            'limit' => 'bail|nullable|integer|min:1|max:50',
            'provider_type_id' => 'bail|nullable|exists:provider_types,id',
        ]);

        $query = Doctor::with('providerType:id,name,icon')
            ->where('user_role', 'provider')
            ->whereStatus(1)
            ->where('is_filled', 1)
            ->whereSubscriptionStatus(1);

        if ($request->filled('provider_type_id')) {
            $query->where('provider_type_id', $request->provider_type_id);
        }

        $providers = $query->get(['id', 'name', 'image', 'address', 'lat', 'lng', 'provider_type_id', 'appointment_fees', 'hourly_rate', 'rate', 'review', 'offers_video_call', 'offers_home_visit']);

        if ($request->filled('lat') && $request->filled('lang')) {
            $providers = $this->getNearProvider($providers, $request->lat, $request->lang);
        }

        $limit = $request->input('limit', 8);
        $providers = $providers->take($limit)->values();

        foreach ($providers as $p) {
            $p->is_faviroute = $this->checkFavourite($p->id);
        }

        return response(['success' => true, 'data' => $providers, 'msg' => 'Providers']);
    }

    /**
     * Single provider details for patient app.
     */
    public function apiSingleProvider(Request $request, $id)
    {
        $request->validate([
            'lat' => 'bail|nullable|numeric',
            'lang' => 'bail|nullable|numeric',
        ]);

        $provider = Doctor::with(['providerType:id,name,description,icon'])->where('user_role', 'provider')->find($id);
        if (! $provider) {
            return response(['success' => false, 'msg' => 'Provider not found'], 404);
        }

        $provider = $provider->makeHidden(['created_at', 'updated_at', 'timeslot', 'dob', 'gender', 'since', 'status', 'based_on', 'hospital_id', 'treatment_id', 'category_id', 'expertise_id']);
        $allowedVisits = ConsultationCapabilities::allowedVisitTypes($provider);
        $provider['offers_video_call'] = in_array('video_call', $allowedVisits, true) ? 1 : 0;
        $provider['offers_home_visit'] = in_array('home_visit', $allowedVisits, true) ? 1 : 0;
        $provider['reviews'] = Review::with('user:id,name,image')->where('doctor_id', $id)->get();

        if ($request->filled('lat') && $request->filled('lang') && $provider->lat != null && $provider->lng != null) {
            $provider['distance_km'] = number_format($this->distance(
                (float) $provider->lat,
                (float) $provider->lng,
                (float) $request->lat,
                (float) $request->lang
            ), 2);
        }

        return response(['success' => true, 'data' => $provider, 'msg' => 'Provider details']);
    }

    public function getNearProvider($providers, $lat, $lang)
    {
        $withDistance = [];
        foreach ($providers as $p) {
            $dist = $this->distance((float) ($p->lat ?? 0), (float) ($p->lng ?? 0), (float) $lat, (float) $lang);
            $p->distance_km = number_format($dist, 2);
            $withDistance[] = $p;
        }
        usort($withDistance, fn ($a, $b) => (float) $a->distance_km <=> (float) $b->distance_km);

        return collect($withDistance);
    }

    public function distance($lat1, $lang1, $lat2, $lang2)
    {
        $lat1 = $lat1;
        $lon1 = $lang1;
        $lat2 = $lat2;
        $lon2 = $lang2;
        $unit = 'K';
        if (($lat1 == $lat2) && ($lon1 == $lon2)) {
            $distance = 0;
        } else {
            $theta = $lon1 - $lon2;
            $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
            $dist = acos($dist);
            $dist = rad2deg($dist);
            $miles = $dist * 60 * 1.1515;
            $unit = strtoupper($unit);
            if ($unit == 'K') {
                $distance = $miles * 1.609344;
            } elseif ($unit == 'N') {
                $distance = $miles * 0.8684;
            } else {
                $distance = $miles;
            }
        }

        return $distance;
    }

    public function apiSingleDoctor(Request $request, $id)
    {
        $request->validate([
            'lat' => 'bail|required',
            'lang' => 'bail|required',
        ]);
        $doctor = Doctor::with(['treatment:id,name', 'expertise:id,name', 'providerType:id,name'])->find($id);
        if (! $doctor || (int) ($doctor->status ?? 0) !== 1 || (int) ($doctor->is_approved ?? 0) !== 1) {
            return response(['success' => false, 'msg' => 'Doctor not found'], 404);
        }
        $doctor = $doctor->makeHidden(['created_at', 'updated_at', 'timeslot', 'dob', 'gender', 'timeslot', 'since', 'status', 'based_on']);
        $radius = Setting::first()->radius;
        $hospitals = Hospital::whereStatus(1)->GetByDistance($request->lat, $request->lang, $radius)->pluck('id')->toArray();

        $h = explode(',', $doctor->hospital_id);
        if (! empty(array_intersect($hospitals, $h))) {
            $hss = array_intersect($hospitals, $h);
            $array = [];
            foreach ($hss as $hospital) {
                $h = Hospital::find($hospital);
                $temp['hospital_distance'] = number_format($this->distance($h['lat'], $h['lng'], $request->lat, $request->lang), 2);
                $temp['hospital_details'] = $h->makeHidden(['lat', 'lng', 'created_at', 'updated_at', 'status']);
                $temp['hospital_gallery'] = HospitalGallery::where('hospital_id', $h->id)->get(['image']);
                array_push($array, $temp);
                $doctor->hospital_id = $array;
            }
        } else {
            $newArr = array_merge($hospitals, $h);
            $array = [];
            if (count($newArr) > 1) {
                foreach ($newArr as $hospital) {
                    $h = Hospital::find($hospital);
                    $temp['hospital_distance'] = number_format($this->distance($h['lat'], $h['lng'], $request->lat, $request->lang), 2);
                    $temp['hospital_details'] = $h->makeHidden(['lat', 'lng', 'created_at', 'updated_at', 'status']);
                    $temp['hospital_gallery'] = HospitalGallery::where('hospital_id', $h->id)->get(['image']);
                    array_push($array, $temp);
                    $doctor->hospital_id = $array;
                }
            } else {
                $doctor->hospital_id = [];
            }
        }
        $allowedVisits = ConsultationCapabilities::allowedVisitTypes($doctor);
        $doctor['offers_video_call'] = in_array('video_call', $allowedVisits, true) ? 1 : 0;
        $doctor['offers_home_visit'] = in_array('home_visit', $allowedVisits, true) ? 1 : 0;
        $doctor['reviews'] = Review::with('user:id,name,image')->where('doctor_id', $id)->get();

        return response(['success' => true, 'data' => $doctor, 'msg' => 'single doctor details']);
    }

    public function apiTimeslot(Request $request)
    {
        $request->validate([
            'date' => 'bail|required|date_format:Y-m-d',
            'doctor_id' => 'bail|required|numeric',
            'duration_minutes' => 'bail|nullable|integer|min:15',
        ]);
        $data = $request->all();
        $duration = isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null;
        $timeslots = (new CustomController)->timeSlot($data['doctor_id'], $data['date'], $duration);

        return response(['success' => true, 'data' => $timeslots, 'msg' => 'Doctor Timeslot']);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass  used to reject reused payment tokens
     */
    private function resolvePayment(array $data, string $modelClass): array
    {
        return (new PaymentGatewayService)->resolve($data, $modelClass, auth()->id());
    }

    public function apiBooking(Request $request)
    {
        $request->validate([
            'appointment_for' => 'bail|required',
            'visit_type' => 'bail|nullable|in:video_call,home_visit',
            'illness_information' => 'bail|nullable',
            'patient_name' => 'bail|required',
            'age' => 'bail|required|numeric',
            'patient_address' => 'bail|required',
            'phone_no' => 'bail|required|numeric|digits_between:6,12',
            'drug_effect' => 'bail|nullable',
            'note' => 'bail|nullable',
            'date' => 'bail|required',
            'time' => 'bail|required',
            'end_time' => 'bail|nullable|string',
            'duration_minutes' => 'bail|nullable|integer|min:15',
            'doctor_id' => 'bail|required',
            'amount' => 'bail|nullable|numeric',
            'payment_type' => 'bail|required',
            'payment_status' => 'bail|nullable',
            'hospital_id' => 'bail|nullable',
            'policy_insurer_name' => 'bail|required_if:is_insured,1',
            'policy_number' => 'bail|required_if:is_insured,1',
        ]);
        $data = $request->except(['appointment_status', 'admin_commission', 'doctor_commission', 'cancel_by', 'cancel_reason', 'discount_id', 'discount_price', 'is_from', 'scheduled_notification_id_doctor', 'scheduled_notification_id_patient']);
        $doctor = Doctor::findOrFail($data['doctor_id']);
        if ((int) $doctor->status !== 1) {
            return response()->json(['success' => false, 'msg' => 'This professional is unavailable.'], 422);
        }
        $visitType = $data['visit_type'] ??
            ($doctor->user_role === 'provider' ? 'home_visit' : 'video_call');
        if (! ConsultationCapabilities::canBook($doctor, $visitType)) {
            return response()->json(['success' => false,
                'msg' => 'This consultation type is not offered by this professional.'], 422);
        }
        $data['visit_type'] = $visitType;
        $date = $data['date'];
        $time = $data['time'];

        $durationMinutes = null;
        $endTime = null;
        if (! empty($data['duration_minutes'])) {
            $durationMinutes = (int) $data['duration_minutes'];
            $startCarbon = \Carbon\Carbon::parse($date.' '.$time);
            $endTime = $startCarbon->copy()->addMinutes($durationMinutes)->format('h:i a');
        } elseif (! empty($data['end_time'])) {
            $endTime = $data['end_time'];
            $startCarbon = \Carbon\Carbon::parse($date.' '.$time);
            $endCarbon = \Carbon\Carbon::parse($date.' '.$endTime);
            $durationMinutes = (int) $startCarbon->diffInMinutes($endCarbon);
        } else {
            $timeslot = $doctor->timeslot == 'other' ? $doctor->custom_timeslot : $doctor->timeslot;
            $durationMinutes = max(15, (int) $timeslot);
            $startCarbon = \Carbon\Carbon::parse($date.' '.$time);
            $endTime = $startCarbon->copy()->addMinutes($durationMinutes)->format('h:i a');
        }

        $data['end_time'] = $endTime;
        $data['duration_minutes'] = $durationMinutes;

        if (! (new CustomController)->slotAvailable($data['doctor_id'], $date, $time, $durationMinutes)) {
            return response(['success' => false, 'msg' => 'This slot is no longer available.'], 422);
        }

        if ($doctor->hourly_rate !== null && (float) $doctor->hourly_rate > 0) {
            $data['amount'] = round((float) $doctor->hourly_rate * ($durationMinutes / 60), 2);
        } else {
            $data['amount'] = (float) ($doctor->appointment_fees ?? 0);
        }
        $offer = Offer::findApplicable($request->offer_code, auth()->id(), $doctor->id, now(env('timezone'))->toDateString());
        if ($request->filled('offer_code') && ! $offer) {
            return response(['success' => false, 'msg' => __('Coupon code is invalid...!!')], 422);
        }
        if ($offer) {
            $data['discount_id'] = $offer->id;
            $data['discount_price'] = $offer->discountFor($data['amount']);
            $data['amount'] = round($data['amount'] - $data['discount_price'], 2);
        }
        unset($data['offer_code']);

        $data['appointment_id'] = '#'.rand(100000, 999999);
        $data['user_id'] = auth()->user()->id;
        $data['appointment_status'] = 'pending';
        $data['is_from'] = '0';
        if (isset($data['report_image'])) {
            $report = [];
            for ($i = 0; $i < count($data['report_image']); $i++) {
                $img = $request->report_image[$i];
                $img = str_replace('data:image/png;base64,', '', $img);
                $img = str_replace(' ', '+', $img);
                $Iname = CustomController::randomFileName('png');
                file_put_contents(public_path('/images/upload/').$Iname, base64_decode($img));
                array_push($report, $Iname);
            }
            $data['report_image'] = json_encode($report);
        }
        $data = $this->resolvePayment($data, Appointment::class);
        if ($doctor->based_on == 'commission') {
            $comm = $data['amount'] * $doctor->commission_amount;
            $data['admin_commission'] = intval($comm / 100);
            $data['doctor_commission'] = intval($data['amount'] - $data['admin_commission']);
        }
        $data['payment_type'] = strtoupper($data['payment_type']);
        // DB columns are NOT NULL; ensure we never pass null for provider bookings
        $data['illness_information'] = $data['illness_information'] ?? '';
        $data['drug_effect'] = $data['drug_effect'] ?? '';
        $appointment = Appointment::create($data);
        if ($offer) {
            $offer->increment('use_count');
        }

        $setting = Setting::first();
        // doctor booked appointment
        $notification_template1 = NotificationTemplate::where('title', 'doctor book appointment')->first();

        $placeholders = [
            '{{doctor_name}}' => $doctor->name,
            '{{appointment_id}}' => $appointment->appointment_id,
            '{{date}}' => $appointment->date,
            '{{user_name}}' => auth()->user()->name,
            '{{app_name}}' => $setting->business_name,
        ];

        $msg1 = $notification_template1->msg_content;
        $mail1 = $notification_template1->mail_content;

        $placeholder_keys = array_keys($placeholders);
        $placeholder_values = array_values($placeholders);
        $mail1 = str_ireplace($placeholder_keys, $placeholder_values, $mail1);
        $msg1 = str_ireplace($placeholder_keys, $placeholder_values, $msg1);

        $doctor_user = User::where('id', $doctor->user_id)->first();
        if ($setting->doctor_mail == 1) {
            try {
                $config = [
                    'driver' => $setting->mail_mailer,
                    'host' => $setting->mail_host,
                    'port' => $setting->mail_port,
                    'from' => ['address' => $setting->mail_from_address, 'name' => $setting->mail_from_name],
                    'encryption' => $setting->mail_encryption,
                    'username' => $setting->mail_username,
                    'password' => $setting->mail_password,
                ];
                Config::set('mail', $config);
                Mail::to($doctor_user->email)->send(new SendMail($mail1, $notification_template1->subject));
            } catch (\Exception $e) {
                info($e->getMessage());
            }
        }

        if ($setting->doctor_notification == 1) {
            try {

                $client = new OneSignalClient($setting->doctor_app_id, $setting->doctor_api_key, $setting->doctor_auth_key);
                $client->async()->sendNotificationCustom([
                    'headings' => ['en' => $setting->business_name],
                    'contents' => ['en' => $msg1],
                    'include_player_ids' => [$doctor_user->device_token],
                ]);
            } catch (\Exception $e) {
                info('OneSignal Error: '.$e->getMessage());
            }
        }

        $doctor_notification = [];
        $doctor_notification['user_id'] = auth()->user()->id;
        $doctor_notification['doctor_id'] = $appointment->doctor_id;
        $doctor_notification['user_type'] = 'doctor';
        $doctor_notification['title'] = 'create appointment';
        $doctor_notification['message'] = $msg1;
        Notification::create($doctor_notification);

        // create Appointment to user
        $notification_template = NotificationTemplate::where('title', 'create appointment')->first();

        $placeholders = [
            '{{user_name}}' => auth()->user()->name,
            '{{appointment_id}}' => $appointment->appointment_id,
            '{{date}}' => $appointment->date,
            '{{time}}' => $appointment->time,
            '{{app_name}}' => $setting->business_name,
        ];

        $msg1 = $notification_template->msg_content;
        $mail1 = $notification_template->mail_content;

        $placeholder_keys = array_keys($placeholders);
        $placeholder_values = array_values($placeholders);
        $mail1 = str_ireplace($placeholder_keys, $placeholder_values, $mail1);
        $msg1 = str_ireplace($placeholder_keys, $placeholder_values, $msg1);

        if ($setting->patient_mail == 1) {
            try {
                $config = [
                    'driver' => $setting->mail_mailer,
                    'host' => $setting->mail_host,
                    'port' => $setting->mail_port,
                    'from' => ['address' => $setting->mail_from_address, 'name' => $setting->mail_from_name],
                    'encryption' => $setting->mail_encryption,
                    'username' => $setting->mail_username,
                    'password' => $setting->mail_password,
                ];
                Config::set('mail', $config);
                Mail::to(auth()->user()->email)->send(new SendMail($mail1, $notification_template->subject));
            } catch (\Exception $e) {
                info($e->getMessage());
            }
        }

        if ($setting->patient_notification == 1) {
            try {
                $client = new OneSignalClient($setting->patient_app_id, $setting->patient_api_key, $setting->patient_auth_key);
                $client->async()->sendNotificationCustom([
                    'headings' => ['en' => $setting->business_name],
                    'contents' => ['en' => $msg1],
                    'include_player_ids' => [auth()->user()->device_token],
                ]);
            } catch (\Exception $e) {
                info('OneSignal Error: '.$e->getMessage());
            }

            (new CustomController)->scheduledReminderNotification($appointment->id);
        }

        $user_notification = [];
        $user_notification['user_id'] = auth()->user()->id;
        $user_notification['doctor_id'] = $appointment->doctor_id;
        $user_notification['user_type'] = 'user';
        $user_notification['title'] = 'create appointment';
        $user_notification['message'] = $msg1;
        Notification::create($user_notification);

        return response(['success' => true, 'data' => $appointment->appointment_id, 'msg' => 'Booking is successfully waiting for doctor confirmation']);
    }

    public function apiAppointments()
    {
        (new CustomController)->cancel_max_order();
        $upcoming = [];
        $pending = [];
        $past = [];
        $appoint = [];
        $appointments = Appointment::with(['hospital:id,address,name'])->where('user_id', auth()->user()->id)->orderBy('id', 'DESC')->get(['id', 'date', 'time', 'end_time', 'duration_minutes', 'appointment_status', 'patient_name', 'doctor_id', 'appointment_id', 'hospital_id', 'visit_type']);
        foreach ($appointments as $appointment) {
            if ($appointment->hospital == null) {
                unset($appointment['hospital']);
                $appointment['hospital'] = (object) [];
                $appointment['hospital_id'] = 0;
            }
            $appointment->doctor = Doctor::with(['treatment:id,name'])->where('id', $appointment->doctor_id)->first(['id', 'name', 'image', 'treatment_id'])->makeHidden(['rate', 'review']);
            $appointment->prescription = Prescription::where('appointment_id', $appointment->id)->exists();
            $appointment->timming = Carbon::parse($appointment['date'].' '.$appointment['time'])->setTimezone(env('timezone'));
            if ($appointment->timming > Carbon::now(env('timezone')) && $appointment->appointment_status == 'approve') {
                unset($appointment['timming']);
                array_push($upcoming, $appointment);
            } elseif ($appointment->timming > Carbon::now(env('timezone')) && $appointment->appointment_status == 'pending') {
                unset($appointment['timming']);
                array_push($pending, $appointment);
            } else {
                unset($appointment['timming']);
                array_push($past, $appointment);
            }
        }
        $appoint['upcoming_appointment'] = $upcoming;
        $appoint['pending_appointment'] = $pending;
        $appoint['past_appointment'] = $past;

        return response(['success' => true, 'data' => $appoint, 'msg' => 'Appointment details']);
    }

    public function apiAppointmentPrescription($id)
    {
        (new CustomController)->cancel_max_order();
        $appointment = Appointment::where('id', $id)->where('user_id', auth()->id())->first(['id', 'date', 'time', 'patient_name', 'doctor_id']);
        if (! $appointment) {
            return response(['success' => false, 'msg' => 'Appointment not found'], 404);
        }
        $appointment->doctor = Doctor::with('treatment:id,name')->where('id', $appointment->doctor_id)->first(['id', 'name', 'image', 'treatment_id']);
        $appointment->prescription = Prescription::where('appointment_id', $id)->first();
        $appointment->prescription['pdfPath'] = url('prescription/upload/'.$appointment->prescription['pdf']);

        return response(['success' => true, 'data' => $appointment, 'msg' => 'Doctor Prescription']);
    }

    public function apiSetting()
    {
        $setting = Setting::first(['cod', 'doctor_app_id', 'paypal', 'stripe', 'razor', 'flutterwave', 'paystack', 'stripe_public_key', 'razor_key', 'patient_app_id', 'playstore', 'appstore', 'privacy_policy', 'about_us', 'agora_app_id', 'cancel_reason', 'flutterwave_key', 'paystack_public_key', 'currency_symbol', 'currency_code', 'isLiveKey', 'paypal_client_id', 'map_key'])->makeHidden(['companyWhite', 'logo', 'favicon']);
        if (! auth('api')->check()) {
            $setting = $setting->makeHidden(['stripe_public_key', 'razor_key', 'agora_app_id', 'flutterwave_key', 'paystack_public_key', 'paypal_client_id']);
        }

        return response(['success' => true, 'data' => $setting, 'msg' => 'Setting']);
    }

    public function apiBlogs()
    {
        $blogs = Blog::where([['status', 1], ['release_now', 1]])->orderBy('id', 'DESC')->get()->makeHidden(['created_at', 'updated_at', 'status', 'release_now']);

        return response(['success' => true, 'data' => $blogs, 'msg' => 'Blogs']);
    }

    public function apiSingleBlog($id)
    {
        $blogs = Blog::where('id', $id)->orderBy('id', 'DESC')->first()->makeHidden(['created_at', 'updated_at', 'status', 'release_now']);

        return response(['success' => true, 'data' => $blogs, 'msg' => 'Single Blog Details']);
    }

    public function apiUpdateProfile(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'dob' => 'required',
            'gender' => 'required',
        ]);
        $id = auth()->user();
        $id->update($request->only(['name', 'dob', 'gender', 'phone', 'phone_code', 'language', 'device_token']));

        return response(['success' => true, 'msg' => 'update successfully']);
    }

    public function apiUpdateImage(Request $request)
    {
        $request->validate(['image' => 'required']);
        $user = $request->user();
        $name = (new \App\Services\ProfileImageUpload)->store($request->file('image') ?? $request->input('image'), public_path('images/upload'));
        $user->update(['image' => $name]);

        return response(['success' => true, 'data' => 'image updated succssfully..!!']);
    }

    public function apiPharmacy(Request $request)
    {
        $pharamacies = Pharmacy::whereStatus(1)->orderBy('id', 'DESC')->get(['id', 'name', 'image', 'address', 'description', 'lat', 'lang']);
        $userLat = $request->has('lat') ? (is_numeric($request->lat) ? (float) $request->lat : null) : null;
        $userLng = $request->has('lang') ? (is_numeric($request->lang) ? (float) $request->lang : null) : null;

        if ($userLat !== null && $userLng !== null) {
            $pharamacies = $pharamacies->map(function ($p) use ($userLat, $userLng) {
                $plat = $p->lat !== null && $p->lat !== '' ? (float) $p->lat : null;
                $plng = $p->lang !== null && $p->lang !== '' ? (float) $p->lang : null;
                $p->distance_km = ($plat !== null && $plng !== null)
                    ? $this->haversineKm($userLat, $userLng, $plat, $plng)
                    : null;
                return $p;
            })->sortBy(function ($p) {
                return $p->distance_km !== null ? $p->distance_km : PHP_INT_MAX;
            })->values();
        }

        return response(['success' => true, 'data' => $pharamacies, 'msg' => 'All phamracy']);
    }

    private function haversineKm($lat1, $lng1, $lat2, $lng2)
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($r * $c, 4);
    }

    public function apiSinglePharmacy($id)
    {
        $pharmacy = Pharmacy::find($id);
        $pharmacy->medicine = Medicine::where('pharmacy_id', $id)->get();

        return response(['success' => true, 'data' => $pharmacy, 'msg' => 'Single Phamracy Details']);
    }

    public function apiSingleMedicine($id)
    {
        $medicine = Medicine::find($id);

        return response(['success' => true, 'data' => $medicine, 'msg' => 'Single Medicine Details']);
    }

    public function apiLabs()
    {
        $labs = Lab::whereStatus(1)->orderBy('id', 'DESC')->get(['id', 'name', 'image', 'address', 'description', 'lat', 'lng']);

        return response(['success' => true, 'data' => $labs, 'msg' => 'All labs']);
    }

    public function apiHospitals()
    {
        $hospitals = Hospital::with('hospital_gallery')
            ->whereStatus(1)
            ->orderBy('id', 'DESC')
            ->get(['id', 'name', 'phone', 'address', 'facility', 'lat', 'lng']);

        return response(['success' => true, 'data' => $hospitals, 'msg' => 'All hospitals']);
    }

    public function apiHospitalDetails($id)
    {
        $hospital = Hospital::with('hospital_gallery')->find($id);
        if (! $hospital) {
            return response(['success' => false, 'msg' => 'Hospital not found']);
        }

        return response(['success' => true, 'data' => $hospital, 'msg' => 'Hospital details']);
    }

    public function apiLabDetails($id)
    {
        $lab = Lab::with('user', 'lab_gallery')->find($id);
        if (! $lab) {
            return response(['success' => false, 'msg' => 'Lab not found']);
        }

        $lab['email'] = $lab->user->email ?? '';
        $lab['phone'] = ($lab->user->phone_code ?? '').($lab->user->phone ?? '');
        return response(['success' => true, 'data' => $lab, 'msg' => 'Lab details']);
    }

    public function apiLabPathology($lab_id)
    {
        $pathology = Pathology::where([['lab_id', $lab_id], ['status', 1]])->get();

        return response(['success' => true, 'data' => $pathology]);
    }

    public function apiLabRadiology($lab_id)
    {
        $radiology = Radiology::where([['lab_id', $lab_id], ['status', 1]])->get();

        return response(['success' => true, 'data' => $radiology]);
    }

    public function apiLabTimeslot(Request $request)
    {
        $request->validate([
            'date' => 'bail|required|date_format:Y-m-d',
            'lab_id' => 'bail|required|numeric',
        ]);
        $timeslots = (new CustomController)->LabtimeSlot($request->lab_id, $request->date);

        return response(['success' => true, 'data' => $timeslots, 'msg' => 'Lab Timeslot']);
    }

    public function apiLabBook(Request $request)
    {
        $request->validate([
            'lab_id' => 'bail|required',
            'patient_name' => 'bail|required',
            'age' => 'bail|required|numeric',
            'phone_no' => 'bail|required|numeric|digits_between:6,12',
            'gender' => 'bail|required',
            'date' => 'bail|required',
            'time' => 'bail|required',
            'payment_type' => 'bail|required',
            'payment_status' => 'bail|nullable',
        ]);
        $data = $request->except(['amount', 'payment_status', 'user_id', 'report_id', 'upload_report']);
        $lab = Lab::find($data['lab_id']);
        if (! $lab) {
            return response(['success' => false, 'msg' => 'Lab not found']);
        }
        $data['user_id'] = auth()->user()->id;
        $data['report_id'] = '#'.rand(100000, 999999);

        [$data, $amount] = $this->priceLabTests($data, $lab->id);
        if ($amount <= 0) {
            return response(['success' => false, 'msg' => 'Please select at least one test.'], 422);
        }
        $data['amount'] = $amount;

        $data = $this->resolvePayment($data, Report::class);
        unset($data['upload_report']);

        $report = Report::create($data);

        $com = $lab->commission * $data['amount'];
        $admin_commission = $com / 100;
        $lab_commission = $data['amount'] - $admin_commission;
        $settle = [];
        $settle['lab_id'] = $lab->id;
        $settle['report_id'] = $report->id;
        $settle['admin_amount'] = $admin_commission;
        $settle['lab_amount'] = $lab_commission;
        $settle['payment'] = $report->payment_status == 1 ? 1 : 0;
        $settle['lab_status'] = 0;
        LabSettle::create($settle);

        return response(['success' => true, 'msg' => 'Lab booked', 'data' => $report]);
    }

    /**
     * Normalises pathology_id/radiology_id to tests of $labId and returns [$data, total charge].
     */
    public static function priceLabTests(array $data, int $labId): array
    {
        $amount = 0.0;
        foreach (['pathology_id' => Pathology::class, 'radiology_id' => Radiology::class] as $field => $model) {
            if (empty($data[$field])) {
                unset($data[$field]);

                continue;
            }
            $ids = is_array($data[$field]) ? $data[$field] : explode(',', (string) $data[$field]);
            $tests = $model::where('lab_id', $labId)->whereIn('id', array_filter(array_map('intval', $ids)))->get(['id', 'charge']);
            if ($tests->isEmpty()) {
                unset($data[$field]);

                continue;
            }
            $data[$field] = $tests->pluck('id')->implode(',');
            $amount += (float) $tests->sum('charge');
        }

        return [$data, $amount];
    }

    public function apiBookMedicine(Request $request)
    {
        $request->validate([
            'pharmacy_id' => 'bail|required|integer',
            'payment_type' => 'bail|required',
            'medicines' => 'bail|required|json',
            'pdf' => 'bail|nullable|file|mimes:pdf|max:10240',
        ]);
        $pharmacy = Pharmacy::findOrFail($request->pharmacy_id);
        $order = (new MedicineOrderPricing)->price(
            $pharmacy,
            json_decode($request->medicines, true) ?: [],
            $request->shipping_at,
            $request->address_id,
            auth()->id()
        );

        $data = [
            'pharmacy_id' => $pharmacy->id,
            'user_id' => auth()->id(),
            'medicine_id' => '#'.rand(100000, 999999),
            'payment_type' => $request->payment_type,
            'payment_token' => $request->payment_token,
            'amount' => $order['items_total'],
            'delivery_charge' => $order['delivery_charge'],
            'shipping_at' => $order['shipping_at'],
            'address_id' => $order['address_id'],
        ];
        $data = (new PaymentGatewayService)->resolve($data, PurchaseMedicine::class, auth()->id(), $order['items_total'] + $order['delivery_charge']);
        if ($request->hasFile('pdf')) {
            $data['pdf'] = (new CustomController)->safeUpload($request->file('pdf'), public_path().'/prescription/upload', ['pdf']);
        }
        $data['admin_commission'] = $data['amount'] * $pharmacy->commission_amount / 100;
        $data['pharmacy_commission'] = $data['amount'] - $data['admin_commission'];

        DB::transaction(function () use ($data, $order) {
            $purchase = PurchaseMedicine::create($data);
            foreach ($order['lines'] as $line) {
                MedicineChild::create([
                    'purchase_medicine_id' => $purchase->id,
                    'medicine_id' => $line['medicine']->id,
                    'price' => $line['price'],
                    'qty' => $line['qty'],
                ]);
                $line['medicine']->increment('use_stock', $line['qty']);
            }
            PharmacySettle::create([
                'purchase_medicine_id' => $purchase->id,
                'pharmacy_id' => $purchase->pharmacy_id,
                'pharmacy_amount' => $purchase->pharmacy_commission,
                'admin_amount' => $purchase->admin_commission,
                'payment' => $purchase->payment_status == 1 ? 1 : 0,
                'pharmacy_status' => 0,
            ]);
        });

        return response(['success' => true, 'msg' => 'Medicine booked']);
    }

    public function apiMedicines()
    {
        $medicines = PurchaseMedicine::with('address')->where('user_id', auth()->user()->id)->get()->makeHidden(['updated_at']);
        foreach ($medicines as $medicine) {
            $medicine['pharmacy_details'] = Pharmacy::find($medicine->pharmacy_id, ['id', 'name', 'image', 'address']);
        }

        return response(['success' => true, 'msg' => 'purchased medicine details', 'data' => $medicines]);
    }

    public function apiForgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'bail|required|email',
        ]);
        PasswordResetController::sendLink($request->email);

        return response(['success' => true, 'msg' => PasswordResetController::GENERIC_MESSAGE]);
    }

    public function apiSingleAppointment($id)
    {
        (new CustomController)->cancel_max_order();
        $appointment = Appointment::with(['user:id,image', 'doctor:id,name,image,treatment_id,user_id', 'hospital:id,name,address'])
            ->where('id', $id)
            ->where('user_id', auth()->user()->id)
            ->first();
        if (! $appointment) {
            return response(['success' => false, 'msg' => 'Appointment not found'], 404);
        }
        $appointment = $appointment->makeHidden(['admin_commission', 'cancel_reason', 'cancel_by', 'discount_id', 'discount_price', 'payment_token', 'created_at', 'updated_at', 'doctor_commission']);
        if ($appointment['report_image'] == null) {
            $appointment['report_image'] = '';
        }
        $appointment['pdf'] = '';
        if (Prescription::where('appointment_id', $id)->exists()) {
            $pdf = Prescription::where('appointment_id', $id)->first()->pdf;
            $appointment['pdf'] = url('prescription/upload').'/'.$pdf;
        }

        return response(['success' => true, 'data' => $appointment, 'msg' => 'Appointment details']);
    }

    public function apiCompleteAppointment(Request $request)
    {
        (new CustomController)->cancel_max_order();
        $request->validate([
            'appointment_id' => 'bail|required|integer',
        ]);
        $appointment = Appointment::where('id', $request->appointment_id)->where('user_id', auth()->user()->id)->first();
        if (! $appointment) {
            return response(['success' => false, 'msg' => 'Appointment not found'], 404);
        }
        if (in_array(strtolower($appointment->appointment_status ?? ''), ['complete', 'completed', 'cancel'])) {
            return response(['success' => false, 'msg' => 'Appointment already closed'], 422);
        }
        $appointment->update(['appointment_status' => 'complete', 'payment_status' => 1]);
        $user = User::find($appointment->user_id);
        $doctor = Doctor::find($appointment->doctor_id);
        if ($doctor && $doctor->based_on == 'commission') {
            $settle = [];
            $settle['appointment_id'] = $appointment->id;
            $settle['doctor_id'] = $appointment->doctor_id;
            $settle['doctor_amount'] = $appointment->doctor_commission;
            $settle['admin_amount'] = $appointment->admin_commission;
            $settle['payment'] = $appointment->payment_type == 'COD' ? 0 : 1;
            $settle['doctor_status'] = 0;
            Settle::create($settle);
        }
        (new CustomController)->statusChangeNotification($user, $appointment, 'complete');

        return response(['success' => true, 'msg' => 'Appointment completed']);
    }

    public function apiCancelAppointment(Request $request)
    {
        $data = $request->all();
        $appointment = Appointment::where('id', $data['appointment_id'] ?? null)->where('user_id', auth()->id())->first();
        if ($appointment) {
            $appointment->appointment_status = 'cancel';
            $appointment->cancel_by = 'user';
            $appointment->cancel_reason = $data['cancel_reason'];
            $appointment->save();
            $doctor = User::where('id', Doctor::where('id', $appointment->doctor_id)->value('user_id'))->first();
            $status = 'canceled';
            (new CustomController)->statusChangeNotification($doctor, $appointment, $status, 'doctor');
            (new CustomController)->cancelScheduledNotification($appointment->scheduled_notification_id_patient, $appointment->scheduled_notification_id_doctor);

            return response(['success' => true, 'msg' => 'appointment cancel']);
        } else {
            return response(['success' => false, 'msg' => 'appointment not found']);
        }
    }

    public function apiAddAddress(Request $request)
    {
        $request->validate([
            'address' => 'bail|required',
            'lat' => 'bail|required',
            'lang' => 'bail|required',
        ]);
        UserAddress::create($request->only(['address', 'lat', 'lang', 'label']) + ['user_id' => auth()->id()]);

        return response(['success' => true, 'msg' => 'Address Added..!!']);
    }

    public function apiShowAddress()
    {
        $addresses = UserAddress::where('user_id', auth()->user()->id)->get(['id', 'address', 'lat', 'lang', 'label']);

        return response(['success' => true, 'data' => $addresses, 'msg' => 'User Address !!']);
    }

    public function apiDeleteAddress($id)
    {
        $address = UserAddress::where('user_id', auth()->id())->find($id);
        if (! $address) {
            return response(['success' => false, 'msg' => 'Address not found'], 404);
        }
        $address->delete();

        return response(['success' => true, 'msg' => 'Address deleted... !!']);
    }

    public function apiAddReview(Request $request)
    {
        $request->validate([
            'review' => 'bail|required',
            'rate' => 'bail|required',
            'appointment_id' => 'bail|required',
        ]);
        $appointment = Review::reviewableAppointment($request->appointment_id, auth()->id());
        if (! $appointment) {
            return response(['success' => false, 'data' => __('Appointment Not Found.!!')]);
        }
        if (Review::where('appointment_id', $appointment->id)->exists()) {
            return response(['success' => false, 'data' => __('Review Already Added.!!')]);
        }
        Review::create([
            'appointment_id' => $appointment->id,
            'doctor_id' => $appointment->doctor_id,
            'user_id' => auth()->id(),
            'review' => $request->review,
            'rate' => max(1, min(5, (int) $request->rate)),
        ]);

        return response(['success' => true, 'data' => __('Thank You For This Review.!!')]);
    }

    public function apiSingleMedicineDetails($purchase_medicine_id)
    {
        $purchase_medicine = PurchaseMedicine::where('user_id', auth()->id())->find($purchase_medicine_id);
        if (! $purchase_medicine) {
            return response(['success' => false, 'msg' => 'Order not found'], 404);
        }

        return response(['success' => true, 'data' => $purchase_medicine]);
    }

    public function apiCheckCoupon(Request $request)
    {
        $request->validate([
            'offer_code' => 'bail|required',
            'date' => 'bail|required',
            'from' => 'bail|required|in:appointment,medicine',
            'doctor_id' => 'bail|required_if:from,appointment',
        ]);
        $data = $request->all();
        $coupen = Offer::where([['offer_code', $request->offer_code], ['status', 1]])->whereColumn('max_use', '>', 'use_count')->first();
        if ($coupen) {
            $users = explode(',', $coupen->user_id);
            $doctors = explode(',', $coupen->doctor_id);
            if (($key = array_search(auth()->user()->id, $users)) !== false) {
                if ($request->from == 'appointment') {
                    if (($key = array_search($request->doctor_id, $doctors)) !== false) {
                        $exploded_date = explode(' - ', $coupen->start_end_date);
                        $currentDate = date('Y-m-d', strtotime($data['date']));
                        if (($currentDate >= $exploded_date[0]) && ($currentDate <= $exploded_date[1])) {
                            $promo = Offer::where('offer_code', $request->offer_code)->first(['id', 'name', 'offer_code', 'discount', 'discount_type', 'is_flat', 'flatDiscount', 'min_discount'])->makeHidden(['fullImage']);

                            return response(['success' => true, 'data' => $promo]);
                        } else {
                            return response(['success' => false, 'msg' => 'Coupon Is Expire.']);
                        }
                    } else {
                        return response(['success' => false, 'msg' => __('Coupon is not valid for this doctor.!')]);
                    }
                } else {
                    $exploded_date = explode(' - ', $coupen->start_end_date);
                    $currentDate = date('Y-m-d', strtotime($data['date']));
                    if (($currentDate >= $exploded_date[0]) && ($currentDate <= $exploded_date[1])) {
                        $promo = Offer::where('offer_code', $request->offer_code)->first(['id', 'name', 'offer_code', 'discount', 'discount_type', 'is_flat', 'flatDiscount', 'min_discount'])->makeHidden(['fullImage']);

                        return response(['success' => true, 'data' => $promo]);
                    } else {
                        return response(['success' => false, 'msg' => 'Coupon Is Expire.']);
                    }
                }
            } else {
                return response(['success' => false, 'msg' => __('Coupon is not valid for this user.!')]);
            }
        } else {
            return response(['success' => false, 'msg' => __('Coupon code is invalid...!!')]);
        }
    }

    public function apiUserNotification()
    {
        $data = Notification::with('doctor:id,name,image')->where([['user_id', auth()->user()->id], ['user_type', 'user']])->get();

        return response(['success' => false, 'data' => $data]);
    }

    public function apiAddBookmark($doctor_id)
    {
        $user_id = auth()->user()->id;
        $faviroute = Faviroute::where([['user_id', $user_id], ['doctor_id', $doctor_id]])->first();
        if (! $faviroute) {
            $data = [];
            $data['user_id'] = $user_id;
            $data['doctor_id'] = $doctor_id;
            Faviroute::create($data);

            return response(['success' => true, 'msg' => __('Added to favorites..!!')]);
        } else {
            $faviroute->delete();

            return response(['success' => true, 'msg' => __('Removed from favorites..!!')]);
        }
    }

    public function apiBanner()
    {
        $data = Banner::get();

        return response(['success' => true, 'data' => $data]);
    }

    public function apiFaviroute()
    {
        $favourites = Faviroute::where('user_id', auth()->user()->id)->get(['doctor_id']);
        $doctors = Doctor::with(['treatment:id,name'])
            ->whereIn('id', $favourites)
            ->where('is_approved', 1)
            ->get(['id', 'status', 'image', 'name', 'treatment_id', 'address', 'lat', 'lng', 'rate', 'review'])
            ->makeHidden(['created_at', 'updated_at']);

        return response(['success' => true, 'data' => $doctors]);
    }

    public function checkFavourite($doctor_id)
    {
        if (auth('api')->user() != null) {
            if (Faviroute::where([['user_id', auth('api')->user()->id], ['doctor_id', $doctor_id]])->first()) {
                return true;
            }

            return false;
        }

        return false;
    }

    public function apiGenerateToken(Request $request)
    {
        $request->validate([
            'to_id' => 'bail|required|integer',
        ]);
        $doctor = Doctor::findOrFail($request->to_id);
        if (! Appointment::hasOpenBooking(auth()->id(), $doctor->id, 'video_call')) {
            return response()->json(['success' => false, 'msg' => 'You can only start a virtual call for a booked video consultation.'], 403);
        }
        $cn = 'c'.bin2hex(random_bytes(12));
        $settings = Setting::select('agora_app_id', 'agora_app_certificate')->first();
        $appID = $settings->agora_app_id;
        $appCertificate = $settings->agora_app_certificate;
        if ($appID != null && $appCertificate != null) {
            $channelName = $cn;
            $uid = 0;
            $role = \RtcTokenBuilder::RolePublisher;
            $expireTimeInSeconds = 180;
            $currentTimestamp = (new DateTime('now', new DateTimeZone(env('timezone'))))->getTimestamp();
            $privilegeExpiredTs = $currentTimestamp + $expireTimeInSeconds;

            $doctor_user = User::find($doctor->user_id);
            $token = \RtcTokenBuilder::buildTokenWithUid($appID, $appCertificate, $channelName, $uid, $role, $privilegeExpiredTs);
            User::whereIn('id', [auth()->id(), $doctor_user->id])->update(['channel_name' => $cn, 'agora_token' => $token]);
            $this->oneSignalNoti($doctor_user->device_token, auth()->user()->name, $token, $cn, $appID);

            return response()->json(['msg' => null, 'data' => ['agora_token' => $token, 'cn' => $cn, 'appId' => $appID], 'success' => true], 200);
        }

        return response()->json(['success' => false, 'msg' => 'Token And ID Not Available'], 200);
    }

    public function oneSignalNoti($userid, $sub, $agoraToken, $channelId, $appId)
    {
        try {
            $settings = Setting::select('doctor_app_id', 'doctor_api_key', 'doctor_auth_key')->first();
            Config::set('onesignal.app_id', $settings->doctor_app_id);
            Config::set('onesignal.rest_api_key', $settings->doctor_api_key);
            Config::set('onesignal.user_auth_key', $settings->doctor_auth_key);
            $data = [
                'name' => $sub,
                'id' => auth()->user()->id,
                'channelId' => $channelId,
                'appId' => $appId,
                'agoraToken' => $agoraToken,
            ];
            // Do not write Agora tokens, channel identifiers or patient data to logs.
            OneSignal::sendNotificationToUser(
                $sub,
                $userid,
                $url = null,
                $data = $data,
                $schedule = null,
                $headings = null,

            );
        } catch (\Exception $e) {
            info($e->getMessage());
        }
    }

    public function apiVideoCallHistory()
    {
        $user = auth()->user();
        if (! $user->hasAnyRole(Role::all())) {
            $history = VideoCallHistory::with(['doctor:id,name,image', 'user:id,name,image'])->where('user_id', auth()->user()->id)->get()->makeHidden(['created_at', 'updated_at']);
        } else {
            $doctor = Doctor::where('user_id', $user->id)->first();
            $history = VideoCallHistory::with(['doctor:id,name,image', 'user:id,name,image'])->where('doctor_id', $doctor->id)->get()->makeHidden(['created_at', 'updated_at']);
        }

        return response(['success' => true, 'data' => $history]);
    }

    public function apiAddHistory(Request $request)
    {
        $request->validate([
            'doctor_id' => 'bail|required',
            'date' => 'bail|required',
            'start_time' => 'bail|required',
            'duration' => 'bail|required',
        ]);
        $data = $request->except(['doctor_id']);
        $doctor = Doctor::where('user_id', auth()->user()->id)->firstOrFail();
        $data['doctor_id'] = $doctor->id;
        VideoCallHistory::create($data);

        return response(['success' => true]);
    }

    public function getNearDoctor($doctor, $lat, $lang)
    {
        $doctors = [];
        $radius = Setting::first()->radius;
        $hospitals = Hospital::whereStatus(1)->GetByDistance($lat, $lang, $radius)->pluck('id')->toArray();
        if (empty($hospitals)) {
            return $doctor;
        }
        foreach ($doctor as $d) {
            $h = explode(',', $d->hospital_id);
            if (! empty(array_intersect($hospitals, $h))) {
                $hss = array_intersect($hospitals, $h);
                $array = [];
                foreach ($hss as $hospital) {
                    $h = Hospital::find($hospital);
                    $dist = $this->distance($h['lat'], $h['lng'], $lat, $lang);
                    $temp = [
                        'hospital_distance' => number_format($dist, 2),
                        'hospital_name' => $h->name,
                        'lat' => $h->lat,
                        'lng' => $h->lng,
                    ];
                    array_push($array, $temp);
                }
                usort($array, fn ($a, $b) => (float) $a['hospital_distance'] <=> (float) $b['hospital_distance']);
                $d->hospital_id = $array;
                $nearest = $array[0];
                // expose distance to nearest hospital (or doctor's own coords) for client-side UI
                $d->distance_km = $nearest['hospital_distance'];
                // Use doctor's own location if set, else nearest hospital
                if (! empty($d->lat) && ! empty($d->lng)) {
                    // keep existing $d->lat, $d->lng from model
                } else {
                    $d->lat = $nearest['lat'];
                    $d->lng = $nearest['lng'];
                }
                array_push($doctors, $d);
            }
        }

        if (empty($doctors)) {
            return $doctor;
        }

        return $doctors;
    }

    public function deleteAccount()
    {
        $user = auth()->user();
        if (Appointment::hasUpcomingForPatient($user->id)) {
            return response()->json(['success' => false, 'message' => 'You have upcoming appointments that need to be canceled first!']);
        }
        (new AccountErasureService)->erase($user);

        return response()->json(['success' => true, 'message' => 'Account Delete Successfully!']);
    }

    public function insurers()
    {
        try {
            $insurers = Insurer::where('status', 1)->get()->makeHidden(['created_at', 'updated_at']);

            return response()->json(['success' => true, 'data' => $insurers]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()]);
        }
    }

    /**
     * Notify a doctor of a conversation update without trusting the sender's
     * chosen message body or an arbitrary device-token destination.
     * This endpoint is disabled until the staging recipient mapping is verified.
     */
    public function apiSendMessageToDoctorNotificationTrigger(Request $request)
    {
        $request->validate([
            'to_device_token' => 'required|string|max:512',
        ]);

        if (! config('k3.chat_push_enabled', false)) {
            return response()->json(['success' => false, 'message' => 'Chat push is unavailable'], 503);
        }

        $patient = $request->user();
        $recipient = User::where('device_token', $request->input('to_device_token'))->first();
        $doctorId = $recipient ? Doctor::where('user_id', $recipient->id)->value('id') : null;

        // Existing booked patient/doctor relationship is the minimum privilege.
        // Do not authorize by token alone. No patient or token details are returned.
        if (! $patient || ! $recipient || ! $doctorId || ! Appointment::query()
            ->where('user_id', $patient->id)
            ->where('doctor_id', $doctorId)
            ->whereNotIn('appointment_status', ['cancel', 'CANCELED', 'cancelled'])
            ->exists()) {
            return response()->json(['success' => false, 'message' => 'Not authorized'], 403);
        }

        $setting = Setting::first();
        if (! $setting || (int) $setting->doctor_notification !== 1) {
            return response()->json(['success' => false, 'message' => 'Chat push is unavailable'], 503);
        }

        try {
            $client = new OneSignalClient(
                $setting->doctor_app_id,
                $setting->doctor_api_key,
                $setting->doctor_auth_key
            );

            $client->async()->sendNotificationCustom([
                'headings' => ['en' => 'New chat message'],
                'contents' => ['en' => 'You have a new message'],
                'include_player_ids' => [$recipient->device_token],
                'data' => [
                    'screen' => 'screen',
                    'userId' => $patient->id,
                    // Retained for compatibility; remove only after device testing.
                    'userToken' => $patient->device_token,
                    'userImage' => $patient->image,
                    'userName' => $patient->name,
                ],
            ]);
        } catch (\Throwable $e) {
            // Never log tokens, message contents or provider error payloads.
            report(new \RuntimeException('Chat push provider failed'));
            return response()->json(['success' => false, 'message' => 'Chat push unavailable'], 503);
        }

        return response()->json(['success' => true, 'message' => 'Notification request accepted']);
    }
}
