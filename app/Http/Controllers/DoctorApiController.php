<?php

namespace App\Http\Controllers;

use App\Http\Controllers\SuperAdmin\CustomController;
use App\Models\Appointment;
use App\Models\Category;
use App\Models\Doctor;
use App\Services\AgoraSessionPolicy;
use App\Models\DoctorSubscription;
use App\Models\Expertise;
use App\Models\Hospital;
use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Prescription;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Settle;
use App\Models\Subscription;
use App\Models\Treatments;
use App\Models\PayoutAccount;
use Illuminate\Support\Facades\Schema;
use App\Models\WithdrawalRequest;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\FirebaseTokenService;
use App\Services\DoctorDocumentService;
use App\Services\PrivatePrescriptionStorage;
use App\Services\PaymentGatewayService;
use App\Services\PaymentReceiptClaimService;
use Berkayk\OneSignal\OneSignalClient;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use OneSignal;

require_once 'RtcTokenBuilder.php';

class DoctorApiController extends Controller
{
    public function apiDoctorLogin(Request $request)
    {
        $request->validate([
            'email' => 'bail|required|email',
            'password' => 'bail|required|min:6',
        ]);

        if (Auth::attempt(['email' => request('email'), 'password' => request('password')])) {
            $userDoctor = Auth::user()->load('roles');
            if ($userDoctor->hasRole('doctor')) {
                if ($userDoctor->verify == 1) {
                    $doctor = Doctor::where('user_id', auth()->user()->id)->first();
                    // Missing or mismatched clinical profiles cannot authenticate as doctors.
                    if (! $doctor || ($doctor->user_role !== null && $doctor->user_role !== 'doctor')) {
                        return response(['success' => false, 'msg' => 'Doctor profile not found.'], 403);
                    }
                    // Revoking either account or profile access must take effect on re-login.
                    if ((int) $userDoctor->status !== 1 || (int) $doctor->status !== 1) {
                        return response(['success' => false, 'msg' => 'You are blocked please contact to admin.'], 403);
                    }
                    // Pending applicants can complete onboarding, but are not given Firebase chat credentials.
                    if (isset($request->device_token)) {
                        $userDoctor->device_token = $request->device_token;
                        $userDoctor->save();
                    }
                    // Response-only fields must be added after persistent updates.
                    // approval_status belongs to the doctor profile, not users.
                    $userDoctor['approval_status'] = $doctor && (int) ($doctor->is_approved ?? 0) === 1 ? 'approved' : 'pending';
                    $userDoctor['is_filled'] = $doctor->is_filled;
                    $firebaseToken = (int) $doctor->is_approved === 1
                        ? (new FirebaseTokenService())->doctorToken($userDoctor->id)
                        : null;
                    if ($doctor->status == 1) {
                        if ($doctor->based_on == 'subscription') {
                            $subscription = DoctorSubscription::where([['doctor_id', $doctor->id], ['status', 1]])->first();
                            if ($subscription) {
                                $cDate = Carbon::parse($doctor['start_time'])->format('Y-m-d');
                                if ($subscription->end_date > $cDate) {
                                    // subscription active
                                    $userDoctor['subscription_status'] = 1;
                                    $doctor->update(['subscription_status' => 1]);
                                    $userDoctor['token'] = $userDoctor->createToken('doctro')->accessToken;
                                    $userDoctor['image'] = $doctor->fullImage;
                                    $userDoctor->makeHidden(['email_verified_at', 'dob', 'gender', 'status', 'doctor_id']);
                                    $userDoctor['device_token'] = $userDoctor['device_token'] == null ? '' : $userDoctor['device_token'];
                                    $userDoctor['language'] = $userDoctor['language'] == null ? '' : $userDoctor['language'];
                                    $userDoctor['channel_name'] = $userDoctor['channel_name'] == null ? '' : $userDoctor['channel_name'];
                                    $userDoctor['agora_token'] = $userDoctor['agora_token'] == null ? '' : $userDoctor['agora_token'];
                                    $userDoctor['firebase_custom_token'] = $firebaseToken;

                                    return response(['success' => true, 'data' => $userDoctor, 'msg' => 'Doctor Login successfully']);
                                } else {
                                    // subscription expire
                                    $userDoctor['subscription_status'] = 0;
                                    $doctor->update(['subscription_status' => 0]);
                                    $userDoctor['token'] = $userDoctor->createToken('doctro')->accessToken;
                                    $userDoctor['image'] = $doctor->fullImage;
                                    $userDoctor->makeHidden(['email_verified_at', 'dob', 'gender', 'status', 'doctor_id']);
                                    $userDoctor['device_token'] = $userDoctor['device_token'] == null ? '' : $userDoctor['device_token'];
                                    $userDoctor['language'] = $userDoctor['language'] == null ? '' : $userDoctor['language'];
                                    $userDoctor['channel_name'] = $userDoctor['channel_name'] == null ? '' : $userDoctor['channel_name'];
                                    $userDoctor['agora_token'] = $userDoctor['agora_token'] == null ? '' : $userDoctor['agora_token'];
                                    $userDoctor['firebase_custom_token'] = $firebaseToken;

                                    return response(['success' => true, 'data' => $userDoctor, 'msg' => 'Your subscription plan is expires']);
                                }
                            } else {
                                $userDoctor['subscription_status'] = 0;
                                $doctor->update(['subscription_status' => 0]);
                                $userDoctor['token'] = $userDoctor->createToken('doctro')->accessToken;
                                $userDoctor['image'] = $doctor->fullImage;
                                $userDoctor->makeHidden(['email_verified_at', 'dob', 'gender', 'status', 'doctor_id']);
                                $userDoctor['device_token'] = $userDoctor['device_token'] == null ? '' : $userDoctor['device_token'];
                                $userDoctor['language'] = $userDoctor['language'] == null ? '' : $userDoctor['language'];
                                $userDoctor['channel_name'] = $userDoctor['channel_name'] == null ? '' : $userDoctor['channel_name'];
                                $userDoctor['agora_token'] = $userDoctor['agora_token'] == null ? '' : $userDoctor['agora_token'];
                                $userDoctor['firebase_custom_token'] = $firebaseToken;

                                return response(['success' => true, 'data' => $userDoctor, 'msg' => 'Your subscription plan is expires']);
                            }
                        } else {
                            $userDoctor['token'] = $userDoctor->createToken('doctro')->accessToken;
                            $userDoctor['image'] = $doctor->fullImage;
                            $userDoctor->makeHidden(['email_verified_at', 'dob', 'gender', 'status', 'doctor_id']);
                            $userDoctor['device_token'] = $userDoctor['device_token'] == null ? '' : $userDoctor['device_token'];
                            $userDoctor['language'] = $userDoctor['language'] == null ? '' : $userDoctor['language'];
                            $userDoctor['channel_name'] = $userDoctor['channel_name'] == null ? '' : $userDoctor['channel_name'];
                            $userDoctor['agora_token'] = $userDoctor['agora_token'] == null ? '' : $userDoctor['agora_token'];
                            $userDoctor['firebase_custom_token'] = $firebaseToken;

                            return response(['success' => true, 'data' => $userDoctor, 'msg' => 'Doctor Login successfully']);
                        }
                    } else {
                        return response(['success' => false, 'msg' => 'You are blocked please contact to admin.']);
                    }
                } else {
                    (new CustomController)->sendOtp($userDoctor);
                    $userDoctor->makeHidden(['email_verified_at', 'dob', 'gender', 'status', 'doctor_id']);

                    return response(['success' => false, 'data' => $userDoctor, 'msg' => 'otp send into your account please verify']);
                }
            } else {
                return response(['success' => false, 'msg' => 'Only doctor can login']);
            }
        } else {
            return response(['success' => false, 'msg' => 'Invalid Email or Password!.']);
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

    public function apiDoctorRegister(Request $request)
    {
        $request->validate([
            'name' => 'bail|required|unique:doctor',
            'email' => 'bail|required|email|unique:users',
            'dob' => 'bail|required|date_format:Y-m-d',
            'gender' => 'bail|required',
            'phone' => 'bail|required|numeric',
            'password' => 'bail|required|min:6',
            'phone_code' => 'bail|required',
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
        $user->assignRole('doctor');
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
        // Newly registered doctors from the app require admin approval.
        $data['is_approved'] = 0;
        $data['name'] = $user->name;
        $data['dob'] = $request->dob;
        $data['gender'] = $request->gender;
        $data['start_time'] = '08:00 am';
        $data['end_time'] = '08:00 pm';
        $data['timeslot'] = 15;
        $data['is_filled'] = 0;
        $data['subscription_status'] = 1;
        $doctor = Doctor::create($data);
        (new DoctorDocumentService)->storeMany($doctor, $request->file('documents') ?? []);
        $userDoctor = User::find($doctor->user_id);
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
        $data['status'] = 1;
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
            // Registration is successful, but doctor must be approved by admin before login/access.
            $userDoctor = User::find($doctor->user_id)->load('roles');
            $userDoctor['is_filled'] = $doctor->is_filled;
            $userDoctor->makeHidden(['email_verified_at', 'dob', 'gender', 'status', 'doctor_id']);
            $userDoctor['approval_status'] = 'pending';

            return response([
                'success' => true,
                'data' => $userDoctor,
                'msg' => 'Registration successful',
            ]);
        } else {
            info('calling otp');
            (new CustomController)->sendOtp($user);
            $user['is_filled'] = $doctor->is_filled;

            return response(['success' => false, 'data' => $user, 'msg' => 'otp send into your account please verify']);
        }
    }

    public function apiDoctorAppointment()
    {

        $doctor = Doctor::where('user_id', auth()->user()->id)->first();

        // (new CustomController)->cancel_max_order();
        // $appointments = Appointment::with(['user:id,image','hospital:id,name,address'])->where('doctor_id',$doctor->id)->whereDate('created_at', Carbon::today())->get(['id','hospital_id','time','date','age','patient_name','amount','patient_address','user_id']);
        // foreach ($appointments as $appointment)
        // {
        //      if ($appointment->hospital == null) {
        //         unset($appointment['hospital']);
        //         $appointment['hospital'] = (object)[];
        //         $appointment['hospital_id'] = 0;
        //     }
        // }

        $appointments['today'] = Appointment::with(['user:id,image', 'hospital:id,name,address'])->where('doctor_id', $doctor->id)->whereDate('date', Carbon::today())->orderBy('time', 'ASC')->get(['id', 'hospital_id', 'time', 'end_time', 'duration_minutes', 'date', 'age', 'patient_name', 'amount', 'patient_address', 'user_id', 'appointment_status', 'visit_type']);
        foreach ($appointments['today'] as $appointment) {
            if ($appointment->hospital == null) {
                unset($appointment['hospital']);
                $appointment['hospital'] = (object) [];
                $appointment['hospital_id'] = 0;
            }
        }

        $appointments['tomorrow'] = Appointment::with(['user:id,image', 'hospital:id,name,address'])->where('doctor_id', $doctor->id)->whereDate('date', Carbon::tomorrow())->orderBy('time', 'ASC')->get(['id', 'hospital_id', 'time', 'end_time', 'duration_minutes', 'date', 'age', 'patient_name', 'amount', 'patient_address', 'user_id', 'appointment_status', 'visit_type']);
        foreach ($appointments['tomorrow'] as $appointment) {
            if ($appointment->hospital == null) {
                unset($appointment['hospital']);
                $appointment['hospital'] = (object) [];
                $appointment['hospital_id'] = 0;
            }
        }
        $appointments['upcoming'] = Appointment::with(['user:id,image', 'hospital:id,name,address'])->where('doctor_id', $doctor->id)->whereDate('date', '>=', new \DateTime('tomorrow + 1day'))->orderBy('time', 'ASC')->get(['id', 'hospital_id', 'time', 'end_time', 'duration_minutes', 'date', 'age', 'patient_name', 'amount', 'patient_address', 'user_id', 'appointment_status', 'visit_type']);
        foreach ($appointments['upcoming'] as $appointment) {
            if ($appointment->hospital == null) {
                unset($appointment['hospital']);
                $appointment['hospital'] = (object) [];
                $appointment['hospital_id'] = 0;
            }
        }

        return response(['success' => true, 'data' => $appointments, 'msg' => 'Doctor Today appointment']);
    }

    public function apiMedicines()
    {
        $medicines = Medicine::whereStatus(1)->get(['id', 'name'])->makeHidden(['fullImage']);

        return response(['success' => true, 'data' => $medicines, 'msg' => 'Medicines']);
    }

    private function ownDoctorId(): ?int
    {
        return Doctor::where('user_id', auth()->id())->value('id');
    }

    public function apiSingleAppointment($id)
    {
        // The patient and doctor apps share this URI, and this route is registered last.
        $doctorId = $this->ownDoctorId();
        if (! $doctorId) {
            return (new UserApiController)->apiSingleAppointment($id);
        }
        $professional = Doctor::query()->whereKey($doctorId)
            ->where('user_id', auth()->id())->where('is_approved', 1)
            ->where('status', 1)->first();
        // This shared endpoint is reachable without the professional
        // middleware. Suspend clinical-record access with the underlying
        // user account, even when an old Passport token is still valid.
        if ((int) (auth()->user()->status ?? 0) !== 1) {
            return response(['success' => false, 'msg' => 'Appointment not found'], 404);
        }
        if (! $professional) {
            return response(['success' => false, 'msg' => 'Appointment not found'], 404);
        }
        (new CustomController)->cancel_max_order();
        $appointment = Appointment::with('user:id,image')->where('id', $id)->where('doctor_id', $doctorId)->first();
        if (! $appointment) {
            return response(['success' => false, 'msg' => 'Appointment not found'], 404);
        }
        $appointment->makeHidden(['admin_commission', 'cancel_reason', 'cancel_by', 'discount_id', 'discount_price', 'payment_token', 'created_at', 'updated_at', 'doctor_commission']);
        if ($appointment['report_image'] == null) {
            $appointment['report_image'] = '';
        }
        $appointment['pdf'] = '';
        if (Prescription::where('appointment_id', $id)->exists()) {
            $pdf = Prescription::where('appointment_id', $id)->first()->pdf;
            $appointment['pdf'] = PrivatePrescriptionStorage::appointmentUrl((int) $id, $pdf);
        }

        return response(['success' => true, 'data' => $appointment, 'msg' => 'Doctor Today appointment']);
    }

    public function apiAddPrescription(Request $request)
    {
        $prescriber = Doctor::where('user_id', auth()->id())->first();
        if (! $prescriber || $prescriber->user_role === 'provider'
            || ! auth()->user()->hasRole('doctor')
            || (int) $prescriber->is_approved !== 1
            || (int) $prescriber->status !== 1) {
            return response()->json(['success' => false,
                'msg' => 'Prescription access requires an approved doctor account.'], 403);
        }
        $request->validate([
            'appointment_id' => 'bail|required',
            'medicines' => 'bail|required|json',
            'user_id' => 'sometimes|integer',
            'pdf' => 'bail|nullable|file|mimes:pdf|max:10240'
        ]);
        $data = $request->all();
        $ownAppointment = Appointment::where('id', $data['appointment_id'])->where('doctor_id', $this->ownDoctorId())->first();
        if (! $ownAppointment) {
            return response(['success' => false, 'msg' => 'Appointment not found'], 404);
        }
        $data['user_id'] = $ownAppointment->user_id;
        $decoded = json_decode($data['medicines'], true);
        if (! is_array($decoded) || ! array_is_list($decoded) || count($decoded) < 1 || count($decoded) > 50) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'medicines' => 'A list of 1 to 50 medicines is required.',
            ]);
        }
        $medicine = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry) || ! isset($entry['medicine'])
                || ! is_scalar($entry['medicine'])
                || strlen((string) $entry['medicine']) > 200
                || trim((string) $entry['medicine']) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'medicines' => 'Invalid medicine details.',
                ]);
            }
            $item = ['medicine' => trim((string) $entry['medicine'])];
            foreach (['days', 'morning', 'afternoon', 'night'] as $field) {
                $value = $entry[$field] ?? null;
                if (! is_scalar($value) && $value !== null) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'medicines' => 'Invalid medicine schedule.',
                    ]);
                }
                $item[$field] = $value;
            }
            $medicine[] = $item;
        }
        $pre['medicines'] = json_encode($medicine);
        $pre['appointment_id'] = $data['appointment_id'];
        $pre['doctor_id'] = Doctor::where('user_id', auth()->user()->id)->first()->id;
        $pre['user_id'] = $data['user_id'];
        $privateFilename = null;
        $privateStore = new PrivatePrescriptionStorage();
        if ($request->hasFile('pdf')) {
            if (PrivatePrescriptionStorage::enabled()) {
                $privateFilename = $privateStore->store($request->file('pdf'));
                $pre['pdf'] = $privateFilename;
            } else {
                // Backward-compatible until both apps can authorize file reads.
                $pre['pdf'] = (new CustomController)->safeUpload(
                    $request->file('pdf'), public_path().'/prescription/upload', ['pdf']
                );
            }
        }
        try {
            $pres = Prescription::create($pre);
        } catch (\Throwable $error) {
            if ($privateFilename !== null) {
                $privateStore->discard($privateFilename);
            }
            throw $error;
        }
        $pdf = PrivatePrescriptionStorage::appointmentUrl(
            (int) $pres->appointment_id, $pres->pdf
        );

        return response(['success' => true, 'data' => $pdf, 'msg' => 'Prescription Created']);
    }

    public function apiWorkingHours()
    {
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        $timing = WorkingHour::where('doctor_id', $doctor->id)->get()->makeHidden(['created_at', 'updated_at']);

        return response(['success' => true, 'data' => $timing, 'msg' => 'Doctor Timming']);
    }

    public function apiUpdateWorkingHours(Request $request)
    {
        $request->validate([
            'id' => 'bail|required',
            'period_list' => 'bail|required',
            'status' => 'bail|required',
        ]);
        $doctor = Doctor::where('user_id', auth()->id())->firstOrFail();
        $working = WorkingHour::where('doctor_id', $doctor->id)->findOrFail($request->id);
        $working->update($request->only(['period_list', 'status']));

        return response(['success' => true, 'msg' => 'Timming updated']);
    }

    public function apiLoginDoctor(Request $request)
    {
        $doctor = Doctor::where('user_id', auth()->user()->id)->first()->makeHidden(['rate', 'review']);
        $doctor->email = User::find($doctor->user_id)->email;
        $doctor->phone = User::find($doctor->user_id)->phone;
        $token = User::find($doctor->user_id)->agora_token;
        $cn = User::find($doctor->user_id)->channel_name;
        $doctor->agora_token = $token == null ? '' : $token;
        $doctor->channel_name = $cn == null ? '' : $cn;

        return response(['success' => true, 'data' => $doctor, 'msg' => 'login doctor details']);
    }

    public function apiUpdateDoctor(Request $request)
    {
        $id = Doctor::where('user_id', auth()->user()->id)->firstOrFail();
        $isProvider = $id->user_role === 'provider';
        $request->validate([
            'name' => 'bail|required',
            'treatment_id' => $isProvider ? 'nullable' : 'bail|required',
            'category_id' => $isProvider ? 'nullable' : 'bail|required',
            'dob' => 'bail|required|date_format:Y-m-d',
            'gender' => 'bail|required',
            'expertise_id' => $isProvider ? 'nullable' : 'bail|required',
            'timeslot' => 'bail|required',
            'start_time' => 'bail|required',
            'end_time' => 'bail|required|after:start_time',
            'hospital_id' => $isProvider ? 'nullable' : 'bail|required',
            'desc' => 'required',
            'appointment_fees' => 'required|numeric',
            'experience' => 'bail|required|numeric',
            'education' => 'bail|required',
            'certificate' => 'bail|required',
            'address' => 'nullable|string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'offers_video_call' => 'nullable|in:0,1',
            'offers_home_visit' => 'nullable|in:0,1',
            'hourly_rate' => 'nullable|numeric|min:0',
        ]);
        $data = $request->only(Doctor::SELF_EDITABLE);
        if ($isProvider) {
            // Providers do not need a physician's treatment, expertise or
            // hospital affiliation. Omit nulls instead of writing NULL into
            // legacy non-nullable doctor-table columns.
            foreach (['treatment_id', 'category_id', 'expertise_id', 'hospital_id']
                as $physicianField) {
                if (! isset($data[$physicianField]) || $data[$physicianField] === '') {
                    unset($data[$physicianField]);
                }
            }
        }
        $data['is_filled'] = 1;
        if (isset($data['hourly_rate']) && $data['hourly_rate'] !== '') {
            $data['hourly_rate'] = (float) $data['hourly_rate'];
        } else {
            $data['hourly_rate'] = null;
        }
        if (isset($data['offers_video_call'])) {
            $data['offers_video_call'] = (int) $data['offers_video_call'];
        }
        // Only providers may offer a home visit; role cannot be overridden by the client.
        $data['offers_home_visit'] = $isProvider
            ? (int) ($data['offers_home_visit'] ?? $id->offers_home_visit ?? 1)
            : 0;
        if (isset($data['lat']) && ($data['lat'] === '' || $data['lat'] === null)) {
            $data['lat'] = null;
        }
        if (isset($data['lng']) && ($data['lng'] === '' || $data['lng'] === null)) {
            $data['lng'] = null;
        }
        $id->update($data);

        return response(['success' => true, 'msg' => 'successfully Update']);
    }

    public function apiDoctorReview()
    {
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        $reviews = Review::with('user:id,name,image')->where('doctor_id', $doctor->id)->get()->makeHidden(['updated_at']);

        return response(['success' => true, 'data' => $reviews, 'msg' => 'Doctor Reviews']);
    }

    public function apiTreatment()
    {
        $treatment = Treatments::whereStatus(1)->get(['id', 'name']);

        return response(['success' => true, 'data' => $treatment, 'msg' => 'Treatments']);
    }

    public function apiCategory($treatment_id)
    {
        $categories = Category::where([['treatment_id', $treatment_id], ['status', 1]])->get(['id', 'name']);

        return response(['success' => true, 'data' => $categories, 'msg' => 'Categories']);
    }

    public function apiExpertise($category_id)
    {
        $expertises = Expertise::where([['category_id', $category_id], ['status', 1]])->get(['id', 'name']);

        return response(['success' => true, 'data' => $expertises, 'msg' => 'Expertises']);
    }

    public function apiHospital()
    {
        $hospitals = Hospital::where('status', 1)->get(['id', 'name']);

        return response(['success' => true, 'data' => $hospitals, 'msg' => 'hospital']);
    }

    public function apiStatusChange(Request $request)
    {
        (new CustomController)->cancel_max_order();
        $validated = $request->validate([
            'id' => 'required|integer|min:1',
            'status' => 'required|in:approve,cancel,complete',
        ]);
        $professional = Doctor::where('user_id', auth()->id())
            ->where('is_approved', 1)->where('status', 1)->first();
        if (! $professional) {
            return response(['success' => false, 'msg' => 'Professional approval required'], 403);
        }

        // Lock the appointment through the close/settlement transition. Without
        // this, two requests can both observe "open" and create duplicate
        // settlement rows for a single clinical encounter.
        $appointment = \Illuminate\Support\Facades\DB::transaction(
            function () use ($validated, $professional) {
                $item = Appointment::where('id', $validated['id'])
                    ->where('doctor_id', $professional->id)
                    ->lockForUpdate()->first();
                if (! $item) {
                    return null;
                }
                if (in_array(strtolower((string) $item->appointment_status),
                    ['complete', 'completed', 'cancel', 'canceled'], true)) {
                    return false;
                }

                $item->update(['appointment_status' => $validated['status']]);
                if ($validated['status'] === 'complete' && $professional->based_on === 'commission') {
                    Settle::firstOrCreate(
                        ['appointment_id' => $item->id],
                        [
                            'doctor_id' => $item->doctor_id,
                            'doctor_amount' => $item->doctor_commission,
                            'admin_amount' => $item->admin_commission,
                            'payment' => $item->payment_type !== 'COD'
                                && (int) $item->payment_status === 1 ? 1 : 0,
                            'doctor_status' => 0,
                        ]
                    );
                }

                return $item;
            }
        );
        if ($appointment === null) {
            return response(['success' => false, 'msg' => 'Appointment not found'], 404);
        }
        if ($appointment === false) {
            return response(['success' => false, 'msg' => 'Appointment already closed'], 422);
        }

        try {
            $patient = User::find($appointment->user_id);
            (new CustomController)->statusChangeNotification(
                $patient, $appointment, $validated['status']
            );
            if ($validated['status'] === 'cancel') {
                (new CustomController)->cancelScheduledNotification(
                    $appointment->scheduled_notification_id_patient,
                    $appointment->scheduled_notification_id_doctor
                );
            }
        } catch (\Throwable $error) {
            // An unavailable push service must not reverse a committed
            // appointment state or leak patient/provider payloads to logs.
            logger()->warning('Appointment status notification delivery failed.');
        }

        return response(['success' => true, 'msg' => 'appointment status change']);
    }

    public function apiAppointmentHistory()
    {
        (new CustomController)->cancel_max_order();
        $doctor = Doctor::with('treatment:id,name')->where('user_id', auth()->user()->id)->firstOrFail();
        $future = [];
        $past = [];
        $appointments = Appointment::with(['user:id,image', 'hospital:id,name,address'])->where('doctor_id', $doctor->id)->orderBy('id', 'DESC')->get(['id', 'date', 'time', 'end_time', 'duration_minutes', 'user_id', 'hospital_id', 'patient_address', 'patient_name', 'appointment_status', 'visit_type']);
        foreach ($appointments as $appointment) {
            if ($appointment->hospital == null) {
                unset($appointment['hospital']);
                $appointment['hospital'] = (object) [];
                $appointment['hospital_id'] = 0;
            }
            $appointment->treatment = $doctor->treatment?->name ?? '';
            $appointment->doctor_name = $doctor->name;
            $appointment->timming = new DateTime($appointment['date'].$appointment['time']);
            if ($appointment->timming >= Carbon::now(env('timezone')) && $appointment->appointment_status != 'completed' && $appointment->appointment_status != 'canceled') {
                unset($appointment['timming']);
                array_push($future, $appointment);
            } else {
                unset($appointment['timming']);
                array_push($past, $appointment);
            }
        }
        $appointment_history['upcoming_appointment'] = $future;
        $appointment_history['past_appointment'] = $past;

        return response(['success' => true, 'data' => $appointment_history]);
    }

    public function apiPayment(Request $request)
    {
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        $payment = Appointment::with('user:id,name')->where('doctor_id', $doctor->id);
        if ($request->month && $request->year) {
            $payment = $payment->whereMonth('created_at', $request->month)->whereYear('created_at', $request->year);
        } else {
            $payment = $payment->whereMonth('created_at', date('m'));
        }
        $payment = $payment->get(['id', 'user_id', 'amount']);

        return response(['success' => true, 'data' => $payment]);
    }

    public function apiSubscription()
    {
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        $subscriptions = Subscription::orderBy('id', 'DESC')->get()->makeHidden(['created_at', 'updated_at']);
        $purchase_subscription = DoctorSubscription::where([['doctor_id', $doctor->id], ['status', 1]])->first();

        return response(['success' => true, 'data' => $subscriptions, 'purchase_subacription' => $purchase_subscription]);
    }

    public function apiFinanceDetails()
    {
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        $finance_details = '';
        if ($doctor->based_on == 'subscription') {
            $finance_details = DoctorSubscription::with('subscription:id,name')->where('doctor_id', $doctor->id)->orderBy('id', 'DESC')->get()->makeHidden('');
            foreach ($finance_details as $value) {
                $value['doctor_name'] = $doctor->name;
            }
        }
        if ($doctor->based_on == 'commission') {
            $past = Carbon::now(env('timezone'))->subDays(35);
            $now = Carbon::today();
            $c = $now->diffInDays($past);
            $loop = $c / 10;
            $data = [];
            while ($now->greaterThan($past)) {
                $t = $past->copy();
                $t->addDay();
                $temp['start'] = $t->toDateString();
                $past->addDays(10);
                if ($past->greaterThan($now)) {
                    $temp['end'] = $now->toDateString();
                } else {
                    $temp['end'] = $past->toDateString();
                }
                array_push($data, $temp);
            }
            $settels = [];
            $orderIds = [];
            foreach ($data as $key) {
                $settle = Settle::where('doctor_id', $doctor->id)->where('created_at', '>=', $key['start'].' 00.00.00')->where('created_at', '<=', $key['end'].' 23.59.59')->get();
                $value['d_total_task'] = $settle->count();
                $value['admin_earning'] = $settle->sum('admin_amount');
                $value['doctor_earning'] = $settle->sum('doctor_amount');
                $value['d_total_amount'] = $value['admin_earning'] + $value['doctor_earning'];
                $remainingOnline = Settle::where([['doctor_id', $doctor->id], ['payment', 0], ['doctor_status', 0]])->where('created_at', '>=', $key['start'].' 00.00.00')->where('created_at', '<=', $key['end'].' 23.59.59')->get();
                $remainingOffline = Settle::where([['doctor_id', $doctor->id], ['payment', 1], ['doctor_status', 0]])->where('created_at', '>=', $key['start'].' 00.00.00')->where('created_at', '<=', $key['end'].' 23.59.59')->get();

                $online = $remainingOnline->sum('doctor_amount'); // admin e devana
                $offline = $remainingOffline->sum('admin_amount'); // admin e levana

                $value['duration'] = $key['start'].' - '.$key['end'];
                $value['d_balance'] = $offline - $online; // vendor earning (positive) or liability (negative)
                array_push($settels, $value);
            }
            $finance_details = $settels;
        }

        $availableBalance = $this->computeAvailableBalance($doctor);

        return response(['success' => true, 'data' => $finance_details, 'available_balance' => $availableBalance]);
    }

    public function apiWithdrawalRequests()
    {
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        if (! $doctor) {
            return response(['success' => false, 'msg' => 'Doctor/Provider not found.', 'data' => []]);
        }
        $holderType = ($doctor->user_role === 'provider') ? WithdrawalRequest::HOLDER_PROVIDER : WithdrawalRequest::HOLDER_DOCTOR;
        $requests = WithdrawalRequest::where('holder_type', $holderType)
            ->where('holder_id', $doctor->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($wr) {
                return [
                    'id' => $wr->id,
                    'amount' => (float) $wr->amount,
                    'status' => $wr->status,
                    'payment_reference' => $wr->payment_reference,
                    'rejected_reason' => $wr->rejected_reason,
                    'created_at' => $wr->created_at->toIso8601String(),
                    'paid_at' => $wr->paid_at?->toIso8601String(),
                ];
            });

        return response(['success' => true, 'data' => $requests]);
    }

    public function apiWithdrawalRequestStore(Request $request)
    {
        $request->validate([
            'amount' => 'bail|required|numeric|min:1',
        ]);
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        if (! $doctor) {
            return response(['success' => false, 'msg' => 'Doctor/Provider not found.']);
        }

        $availableBalance = $this->computeAvailableBalance($doctor);
        if ((float) $request->amount > $availableBalance) {
            return response(['success' => false, 'msg' => 'Withdrawal amount exceeds available balance.'], 422);
        }

        $holderType = ($doctor->user_role === 'provider') ? WithdrawalRequest::HOLDER_PROVIDER : WithdrawalRequest::HOLDER_DOCTOR;

        // Snapshot the payout account details at time of request
        $payoutAccount = null;
        if (Schema::hasTable('payout_accounts')) {
            $payoutHolderType = ($doctor->user_role === 'provider') ? PayoutAccount::HOLDER_PROVIDER : PayoutAccount::HOLDER_DOCTOR;
            $payoutAccount = PayoutAccount::where('holder_type', $payoutHolderType)
                ->where('holder_id', $doctor->id)
                ->first();
        }

        if (! $payoutAccount) {
            return response(['success' => false, 'msg' => 'Please add your payout account details before requesting a withdrawal.'], 422);
        }

        $wr = WithdrawalRequest::create([
            'holder_type' => $holderType,
            'holder_id' => $doctor->id,
            'amount' => $request->amount,
            'payout_account_name' => $payoutAccount->account_name,
            'payout_account_number' => $payoutAccount->account_number,
            'payout_bank_name' => $payoutAccount->bank_name,
            'status' => WithdrawalRequest::STATUS_PENDING,
        ]);

        return response(['success' => true, 'msg' => 'Withdrawal request submitted.', 'data' => ['id' => $wr->id]]);
    }

    public function apiPayoutAccountShow()
    {
        if (! Schema::hasTable('payout_accounts')) {
            return response(['success' => true, 'data' => null]);
        }

        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        if (! $doctor) {
            return response(['success' => false, 'msg' => 'Doctor/Provider not found.']);
        }
        $holderType = ($doctor->user_role === 'provider') ? PayoutAccount::HOLDER_PROVIDER : PayoutAccount::HOLDER_DOCTOR;
        $account = PayoutAccount::where('holder_type', $holderType)
            ->where('holder_id', $doctor->id)
            ->first();

        if (! $account) {
            return response(['success' => true, 'data' => null]);
        }

        return response([
            'success' => true,
            'data' => [
                'account_name' => $account->account_name,
                'account_number' => $account->account_number,
                'bank_name' => $account->bank_name,
            ],
        ]);
    }

    public function apiPayoutAccountUpdate(Request $request)
    {
        if (! Schema::hasTable('payout_accounts')) {
            return response(['success' => false, 'msg' => 'Payout accounts table not migrated yet.']);
        }

        $request->validate([
            'account_name' => 'bail|required|string|max:190',
            'account_number' => 'bail|required|string|max:50',
            'bank_name' => 'bail|required|string|max:190',
        ]);

        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        if (! $doctor) {
            return response(['success' => false, 'msg' => 'Doctor/Provider not found.']);
        }

        $holderType = ($doctor->user_role === 'provider') ? PayoutAccount::HOLDER_PROVIDER : PayoutAccount::HOLDER_DOCTOR;

        $account = PayoutAccount::updateOrCreate(
            [
                'holder_type' => $holderType,
                'holder_id' => $doctor->id,
            ],
            [
                'account_name' => $request->account_name,
                'account_number' => $request->account_number,
                'bank_name' => $request->bank_name,
            ]
        );

        return response([
            'success' => true,
            'msg' => 'Payout account saved.',
            'data' => [
                'account_name' => $account->account_name,
                'account_number' => $account->account_number,
                'bank_name' => $account->bank_name,
            ],
        ]);
    }

    public function apiNotification()
    {
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        $data = Notification::with('user:id,name,image')->where([['doctor_id', $doctor->id], ['user_type', 'doctor']])->get();

        return response(['success' => true, 'data' => $data]);
    }

    public function apiPurchaseSubscription(Request $request)
    {
        $request->validate([
            'subscription_id' => 'bail|required',
            'payment_status' => 'bail|nullable',
            'payment_type' => 'bail|required',
            'duration' => 'bail|required|integer|min:1',
        ]);
        $doctor = Doctor::where('user_id', auth()->user()->id)->firstOrFail();
        $plan = collect(json_decode(Subscription::findOrFail($request->subscription_id)->plan, true))
            ->first(fn ($entry) => (int) ($entry['month'] ?? 0) === (int) $request->duration);
        if (! $plan) {
            throw ValidationException::withMessages(['duration' => __('Invalid subscription duration.')]);
        }
        $data = $request->only(['subscription_id', 'duration', 'payment_type', 'payment_token', 'payment_status']);
        $data['amount'] = (float) $plan['price'];
        $data = (new PaymentGatewayService)->resolve($data, DoctorSubscription::class, auth()->id());
        $paid = (int) ($data['payment_status'] ?? 0) === 1;
        $data['doctor_id'] = $doctor->id;
        $data['start_date'] = Carbon::now(env('timezone'))->format('Y-m-d');
        $data['end_date'] = Carbon::now(env('timezone'))->addMonths($data['duration'])->format('Y-m-d');
        // An unpaid request must never become the active subscription or
        // deactivate a previously verified/paid plan.
        $data['status'] = $paid ? 1 : 0;
        $subscription = \Illuminate\Support\Facades\DB::transaction(function () use ($doctor, $data, $paid) {
            // Serialize Plus and web renewals on the same professional row.
            Doctor::whereKey($doctor->id)->lockForUpdate()->firstOrFail();
            if ($paid) {
                DoctorSubscription::where([['status', 1], ['doctor_id', $doctor->id]])
                    ->update(['status' => 0]);
            }
            $created = DoctorSubscription::create($data);
            (new PaymentReceiptClaimService())->claim(
                $data, 'subscription', (int) $created->id, (int) $doctor->user_id
            );
            if ($paid) {
                $doctor->subscription_status = 1;
                $doctor->save();
            }
            return $created;
        });
        if ($subscription->payment_type == 'FLUTTERWAVE' && ! $paid) {
            return response(['success' => true, 'url' => url('subscription_flutter/'.$subscription->id)]);
        }

        return response(['success' => true]);
    }

    public function apiPaystackInit(Request $request)
    {
        $request->validate([
            'amount' => 'bail|required|numeric|min:1|max:1000000000',
            'amount_unit' => 'sometimes|in:major,minor',
            'email' => 'sometimes|email',
            'currency' => 'bail|required|string|max:3',
        ]);
        $secretKey = config('services.paystack.secret');
        if (! $secretKey) {
            return response(['success' => false, 'msg' => 'Payment gateway unavailable'], 503);
        }

        $currency = strtoupper((string) Setting::query()->value('currency_code'));
        if ($currency === '' || strtoupper((string) $request->currency) !== $currency) {
            return response(['success' => false, 'msg' => 'Unsupported payment currency'], 422);
        }
        $payer = $request->user('api');
        if (! $payer || ! $payer->email) {
            return response(['success' => false, 'msg' => 'Account verification required'], 403);
        }
        // Paystack accepts minor currency units. The payer identity and
        // metadata must be server-derived, never client-controlled.
        // Existing Plus clients send kobo; new clients explicitly declare major units.
        $majorUnits = $request->input('amount_unit', 'minor') === 'major';
        if (($majorUnits && (float) $request->amount > 10000000)
            || (!$majorUnits && (float) $request->amount !== floor((float) $request->amount))) {
            return response(['success' => false, 'msg' => 'Invalid payment amount'], 422);
        }
        $amountMinor = (int) round(((float) $request->amount) * ($majorUnits ? 100 : 1));
        $callbackUrl = rtrim((string) config('app.url'), '/').'/api/paystack/return';
        try {
        $response = Http::withToken($secretKey)
            ->timeout(20)->withOptions(['allow_redirects' => false])
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $payer->email,
                'amount' => $amountMinor,
                'currency' => $currency,
                'metadata' => ['user_id' => (string) $payer->id],
                'callback_url' => $callbackUrl,
            ]);
        } catch (\Throwable $exception) {
            logger()->warning('Paystack checkout initialization unavailable.');
            return response(['success' => false, 'msg' => 'Paystack initialization failed'], 502);
        }
        if (! $response->successful()) {
            return response(['success' => false, 'msg' => 'Paystack initialization failed'], 502);
        }

        $data = $response->json('data');
        $checkout = is_array($data) ? parse_url((string) ($data['authorization_url'] ?? '')) : false;
        if ($response->json('status') !== true || !is_array($data)
            || !is_string($data['reference'] ?? null) || !preg_match('/^[A-Za-z0-9._=-]{1,200}$/D', $data['reference'])
            || !is_string($data['access_code'] ?? null) || $data['access_code'] === ''
            || !is_array($checkout) || ($checkout['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($checkout['host'] ?? ''), ['checkout.paystack.com', 'checkout.paystack.co', 'standard.paystack.co'], true)
            || isset($checkout['user']) || isset($checkout['pass']) || isset($checkout['fragment'])
            || (isset($checkout['port']) && $checkout['port'] !== 443)) {
            return response(['success' => false, 'msg' => 'Paystack initialization failed'], 502);
        }
        return response([
            'success' => true,
            'data' => [
                'access_code' => $data['access_code'] ?? null,
                'reference' => $data['reference'] ?? null,
                'authorization_url' => $data['authorization_url'],
                'callback_url' => $callbackUrl,
            ],
        ]);
    }

    public function apiUpdateImage(Request $request)
    {
        $request->validate(['image' => 'required']);
        $doctor = Doctor::where('user_id', auth()->id())->firstOrFail();
        $name = (new \App\Services\ProfileImageUpload)->store($request->file('image') ?? $request->input('image'), public_path('images/upload'));
        $previousImage = $doctor->image;
        $doctor->update(['image' => $name]);
        // Only delete server-generated filenames after the replacement is saved.
        if (preg_match('/^[a-f0-9]{32}\\.(png|jpg)$/', (string) $previousImage)) {
            (new CustomController)->deleteFile($previousImage);
        }

        return response(['success' => true, 'data' => $doctor->fullImage]);
    }

    public function apiCancelAppointment()
    {
        $doctor = Doctor::with('treatment:id,name')->where('user_id', auth()->user()->id)->first();
        $cancel_appointment = Appointment::with('user:id,image')->where([['doctor_id', $doctor->id], ['appointment_status', 'CANCELED']])->get(['id', 'date', 'time', 'user_id', 'patient_address', 'patient_name', 'age', 'amount']);

        return response(['success' => true, 'data' => $cancel_appointment]);
    }

    public function apiDoctorChangePassword(Request $request)
    {
        $request->validate([
            'old_password' => 'bail|required|min:6',
            'password' => 'bail|required|min:6',
            'password_confirmation' => 'bail|required|min:6',
        ]);
        $data = $request->all();
        $id = auth()->user();
        if (Hash::check($data['old_password'], $id->password) == true) {
            if ($data['password'] == $data['password_confirmation']) {
                $id->password = Hash::make($data['password']);
                $id->save();

                return response(['success' => true, 'data' => 'Password Update Successfully...!!']);
            } else {
                return response(['success' => false, 'data' => 'password and confirm password does not match']);
            }
        } else {
            return response(['success' => false, 'data' => 'Old password does not match.']);
        }
    }

    public function apiDoctorGenerateToken(Request $request)
    {
        $request->validate([
            'to_id' => 'bail|required|integer',
        ]);
        $doctor = Doctor::where('user_id', auth()->id())
            ->where('is_approved', 1)->where('status', 1)->firstOrFail();
        if (! Appointment::hasOpenBooking((int) $request->to_id, $doctor->id, 'video_call')) {
            return response()->json(['success' => false, 'msg' => 'You can only call a patient with a booked virtual consultation.'], 403);
        }
        $patient_user = User::find($request->to_id);
        if (! $patient_user || $patient_user->id == auth()->id()) {
            return response()->json(['success' => false, 'msg' => 'Consultation recipient unavailable'], 403);
        }
        $cn = 'c'.bin2hex(random_bytes(12));
        $settings = Setting::select('agora_app_id', 'agora_app_certificate')->first();
        $appID = $settings ? $settings->agora_app_id : null;
        $appCertificate = $settings ? $settings->agora_app_certificate : null;
        if (is_string($appID) && trim($appID) !== '' && is_string($appCertificate) && trim($appCertificate) !== '') {
            $channelName = $cn;
            $uid = 0;
            $role = \RtcTokenBuilder::RolePublisher;
            $expireTimeInSeconds = AgoraSessionPolicy::TOKEN_TTL_SECONDS;
            $currentTimestamp = (new DateTime('now', new DateTimeZone(env('timezone'))))->getTimestamp();
            $privilegeExpiredTs = $currentTimestamp + $expireTimeInSeconds;
            // Find the Patient User Model for Call Receiver

            $token = \RtcTokenBuilder::buildTokenWithUid($appID, $appCertificate, $channelName, $uid, $role, $privilegeExpiredTs);

            // Find the User Model for Patient
            User::whereIn('id', [auth()->id(), $patient_user->id])->update(['channel_name' => $cn, 'agora_token' => $token]);

            $this->oneSignalNoti($patient_user->device_token, auth()->user()->name, $token, $cn, $appID);

            return response()->json(['msg' => null, 'data' => ['agora_token' => $token, 'cn' => $cn, 'appId' => $appID], 'success' => true], 200);
        }

        return response()->json(['success' => false, 'msg' => 'Token And ID Not Available'], 404);
    }

    public function oneSignalNoti($userid, $sub, $agoraToken, $channelId, $appId)
    {
        $settings = Setting::select('patient_app_id', 'patient_api_key', 'patient_auth_key')->first();
        try {
            Config::set('onesignal.app_id', $settings->patient_app_id);
            Config::set('onesignal.rest_api_key', $settings->patient_api_key);
            Config::set('onesignal.user_auth_key', $settings->patient_auth_key);
            $data = [
                'name' => $sub,
                'id' => auth()->user()->id,
                'channelId' => $channelId,
                'appId' => $appId,
                'agoraToken' => $agoraToken,
            ];
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

    public function apiUpdatePatientVCallSwitch(Request $request)
    {
        $doctor = Doctor::where('user_id', auth()->user()->id)->first();
        $doctor->patient_vcall = $request->patient_vcall;
        $doctor->save();

        return response(['success' => true, 'msg' => 'Updated'], 200);
    }

    public function sendTestNotification(Request $request)
    {
        $request->validate([
            'email' => 'bail|required|email',
            'role' => 'bail|required|in:doctor,patient',
        ]);

        try {
            $res = (new CustomController)->sendTestNotification($request->email, $request->role);

            return response(['success' => true, 'msg' => $res], 200);
        } catch (\Throwable $th) {
            return response(['success' => false, 'msg' => $res ?? 'Unknown Error'], 404);
        }
    }

    /**
     * Notify a patient only when the authenticated doctor holds a non-cancelled
     * appointment with that patient. Never trust a client-authored push body.
     */
    public function apiSendMessageToPatientNotificationTrigger(Request $request)
    {
        $request->validate([
            'to_device_token' => 'required|string|max:512',
        ]);

        if (! config('k3.chat_push_enabled', false)) {
            return response()->json(['success' => false, 'message' => 'Chat push is unavailable'], 503);
        }

        $doctorUser = $request->user();
        $doctor = $doctorUser ? Doctor::where('user_id', $doctorUser->id)
            ->where('is_approved', 1)->where('status', 1)->first() : null;
        $recipient = User::where('device_token', $request->input('to_device_token'))->first();

        if (! $doctor || ! $recipient || ! Appointment::query()
            ->where('user_id', $recipient->id)
            ->where('doctor_id', $doctor->id)
            ->whereNotIn('appointment_status', ['cancel', 'CANCELED', 'cancelled'])
            ->exists()) {
            return response()->json(['success' => false, 'message' => 'Not authorized'], 403);
        }

        $setting = Setting::first();
        if (! $setting || (int) $setting->patient_notification !== 1) {
            return response()->json(['success' => false, 'message' => 'Chat push is unavailable'], 503);
        }

        try {
            $client = new OneSignalClient(
                $setting->patient_app_id,
                $setting->patient_api_key,
                $setting->patient_auth_key
            );

            $client->async()->sendNotificationCustom([
                'headings' => ['en' => 'New chat message'],
                'contents' => ['en' => 'You have a new message'],
                'include_player_ids' => [$recipient->device_token],
                'data' => [
                    'screen' => 'screen',
                    'userId' => $doctorUser->id,
                    'doctorId' => $doctor->id,
                    // Retained for app compatibility until notification navigation is tested.
                    'userToken' => $doctorUser->device_token,
                    'userImage' => $doctorUser->image,
                    'userName' => $doctorUser->name,
                ],
            ]);
        } catch (\Throwable $e) {
            report(new \RuntimeException('Chat push provider failed'));
            return response()->json(['success' => false, 'message' => 'Chat push unavailable'], 503);
        }

        return response()->json(['success' => true, 'message' => 'Notification request accepted']);
    }

    private function computeAvailableBalance(Doctor $doctor): float
    {
        $holderType = ($doctor->user_role === 'provider')
            ? WithdrawalRequest::HOLDER_PROVIDER
            : WithdrawalRequest::HOLDER_DOCTOR;

        if ($doctor->based_on == 'commission') {
            // Count earnings from all non-cancelled appointments (approve = confirmed/paid, complete = done)
            $totalEarned = (float) Appointment::where('doctor_id', $doctor->id)
                ->whereNotIn('appointment_status', ['cancel', 'CANCELED', 'cancelled'])
                ->sum('doctor_commission');
        } else {
            $totalEarned = (float) DoctorSubscription::where('doctor_id', $doctor->id)
                ->where('payment_status', 1)
                ->sum('amount');
        }

        $reservedAmount = (float) WithdrawalRequest::where('holder_type', $holderType)
            ->where('holder_id', $doctor->id)
            ->whereIn('status', [
                WithdrawalRequest::STATUS_PENDING,
                WithdrawalRequest::STATUS_APPROVED,
                WithdrawalRequest::STATUS_PAID,
            ])
            ->sum('amount');

        return max(0.0, $totalEarned - $reservedAmount);
    }
}
