<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Mail\SendMail;
use App\Models\Appointment;
use App\Models\Country;
use App\Models\Category;
use App\Models\Doctor;
use App\Models\Expertise;
use App\Models\Settle;
use App\Models\DoctorSubscription;
use App\Models\ProviderType;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Treatments;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class ProviderController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('provider_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $providers = Doctor::with('providerType')->withCount('documents')->where('user_role', 'provider')->orderBy('id', 'desc')->get();
        foreach ($providers as $provider) {
            $provider->user = User::find($provider->user_id);
        }

        return view('superAdmin.provider.provider', compact('providers'));
    }

    public function create()
    {
        abort_if(Gate::denies('provider_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $providerTypes = ProviderType::whereStatus(1)->get();
        $countries = Country::get();

        return view('superAdmin.provider.create_provider', compact('providerTypes', 'countries'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'bail|required|unique:doctor,name',
            'email' => 'bail|required|email|unique:users',
            'provider_type_id' => 'bail|required|exists:provider_types,id',
            'dob' => 'bail|required|date',
            'gender' => 'bail|required',
            'phone' => 'bail|required|digits_between:6,12',
            'phone_code' => 'bail|required|string',
            'address' => 'nullable|string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'desc' => 'required',
            'appointment_fees' => 'required|numeric',
            'hourly_rate' => 'nullable|numeric|min:0',
            'experience' => 'bail|required|numeric',
            'degree' => 'bail|required|array',
            'degree.*' => 'required|string',
            'certificate' => 'bail|required|array',
            'certificate.*' => 'required|string',
            'password' => 'sometimes|min:6',
        ]);

        $data = $request->all();
        $password = (isset($data['password']) && $data['password'] != '') ? $data['password'] : mt_rand(100000, 999999);
        unset($data['password']);

        $setting = Setting::first();
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($password),
            'verify' => 1,
            'phone' => $data['phone'],
            'phone_code' => $data['phone_code'],
            'image' => 'defaultUser.png',
            'status' => 1,
            'dob' => $data['dob'],
            'gender' => $data['gender'],
        ]);
        $user->assignRole('provider');

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
            Mail::to($user->email)->send(new SendMail('Dear Provider, your password is : '.$password, 'Provider Password'));
        } catch (\Exception $e) {
            info($e);
        }

        $data['user_id'] = $user->id;
        $data['image'] = $request->hasFile('image') ? (new CustomController)->imageUpload($request->image) : 'defaultUser.png';
        $data['based_on'] = $request->input('based_on', $setting->default_base_on);
        if ($data['based_on'] == 'commission') {
            $data['commission_amount'] = $request->input('commission_amount', $setting->default_commission ?? 0);
        } else {
            $data['commission_amount'] = null;
        }
        $data['since'] = Carbon::now(env('timezone'))->format('Y-m-d , h:i A');
        $data['status'] = 1;
        $data['subscription_status'] = 1;
        $data['is_filled'] = 1;
        $data['user_role'] = 'provider';
        $data['offers_video_call'] = 0;
        $data['offers_home_visit'] = 1;
        $data['start_time'] = '08:00 am';
        $data['end_time'] = '08:00 pm';
        $data['timeslot'] = 15;
        $data['hospital_id'] = '';
        $data['hourly_rate'] = (isset($data['hourly_rate']) && $data['hourly_rate'] !== '' && $data['hourly_rate'] !== null)
            ? (float) $data['hourly_rate']
            : 0;
        $treat = Treatments::whereStatus(1)->first();
        $data['treatment_id'] = $treat?->id ?? 1;
        $cat = Category::whereStatus(1)->first();
        $data['category_id'] = $cat?->id ?? 1;
        $exp = Expertise::whereStatus(1)->first();
        $data['expertise_id'] = $exp?->id ?? 1;

        $education = [];
        for ($i = 0; $i < count($data['degree'] ?? []); $i++) {
            $temp = ['degree' => $data['degree'][$i], 'college' => $data['college'][$i] ?? '', 'year' => $data['year'][$i] ?? ''];
            array_push($education, $temp);
        }
        $data['education'] = json_encode($education);

        $certificate = [];
        for ($i = 0; $i < count($data['certificate'] ?? []); $i++) {
            $temp1 = ['certificate' => $data['certificate'][$i], 'certificate_year' => $data['certificate_year'][$i] ?? ''];
            array_push($certificate, $temp1);
        }
        $data['certificate'] = json_encode($certificate);

        $provider = Doctor::create($data);

        if ($provider->based_on == 'subscription') {
            $subscription = Subscription::where('name', 'free')->first();
            if ($subscription) {
                DoctorSubscription::create([
                    'doctor_id' => $provider->id,
                    'subscription_id' => $subscription->id,
                    'duration' => 1,
                    'start_date' => Carbon::now(env('timezone'))->format('Y-m-d'),
                    'end_date' => Carbon::now(env('timezone'))->addMonths(1)->format('Y-m-d'),
                    'status' => 1,
                    'payment_status' => 1,
                ]);
            }
        }

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        foreach ($days as $day) {
            WorkingHour::create([
                'doctor_id' => $provider->id,
                'period_list' => json_encode([['start_time' => '08:00 am', 'end_time' => '08:00 pm']]),
                'day_index' => $day,
                'status' => 1,
            ]);
        }

        return redirect('provider')->withStatus(__('Provider created successfully.'));
    }

    public function edit($id)
    {
        abort_if(Gate::denies('provider_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $provider = Doctor::where('user_role', 'provider')->findOrFail($id);
        $provider->user = User::find($provider->user_id);
        $providerTypes = ProviderType::whereStatus(1)->get();
        $countries = Country::get();
        $provider['hospital_id'] = [];

        return view('superAdmin.provider.edit_provider', compact('provider', 'providerTypes', 'countries'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'bail|required|unique:doctor,name,'.$id.',id',
            'provider_type_id' => 'bail|required|exists:provider_types,id',
            'dob' => 'bail|required|date',
            'gender' => 'bail|required',
            'phone' => 'bail|required|digits_between:6,12',
            'address' => 'nullable|string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'desc' => 'required',
            'appointment_fees' => 'required|numeric',
            'hourly_rate' => 'nullable|numeric|min:0',
            'experience' => 'bail|required|numeric',
            'education' => 'bail|required',
            'certificate' => 'bail|required',
            'password' => 'sometimes|min:6',
        ]);

        $provider = Doctor::where('user_role', 'provider')->findOrFail($id);
        $data = $request->all();

        if (isset($data['password']) && $data['password'] != '') {
            User::find($provider->user_id)->update(['password' => Hash::make($data['password'])]);
        }
        unset($data['password']);
        User::find($provider->user_id)->update([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'phone_code' => $data['phone_code'],
            'dob' => $data['dob'],
            'gender' => $data['gender'],
        ]);
        unset($data['phone'], $data['phone_code']);

        if ($request->hasFile('image')) {
            (new CustomController)->deleteFile($provider->image);
            $data['image'] = (new CustomController)->imageUpload($request->image);
        }

        $education = [];
        foreach ($data['degree'] ?? [] as $i => $deg) {
            $education[] = ['degree' => $deg, 'college' => $data['college'][$i] ?? '', 'year' => $data['year'][$i] ?? ''];
        }
        $data['education'] = json_encode($education);

        $certificate = [];
        foreach ($data['certificate'] ?? [] as $i => $cert) {
            $certificate[] = ['certificate' => $cert, 'certificate_year' => $data['certificate_year'][$i] ?? ''];
        }
        $data['certificate'] = json_encode($certificate);

        $provider->update($data);

        return redirect('provider')->withStatus(__('Provider updated successfully.'));
    }

    public function destroy($id)
    {
        abort_if(Gate::denies('provider_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $provider = Doctor::where('user_role', 'provider')->findOrFail($id);
        $user = User::find($provider->user_id);
        $user->removeRole('provider');
        $user->delete();
        (new CustomController)->deleteFile($provider->image);
        $provider->delete();

        return response(['success' => true]);
    }

    public function show($id, $name, $with)
    {
        $provider = Doctor::with('providerType')->where('user_role', 'provider')->findOrFail($id);
        $currency = Setting::first()->currency_symbol;

        if ($with == 'dashboard') {
            $totalAppointments = Appointment::where('doctor_id', $id)->get();

            return view('superAdmin.provider.show_provider', compact('provider', 'currency', 'totalAppointments'));
        }
        if ($with == 'appointment') {
            return view('superAdmin.provider.provider_appointment', compact('provider'));
        }
        if ($with == 'schedule') {
            $provider->workingHours = WorkingHour::where('doctor_id', $id)->get();
            $provider->firstHours = WorkingHour::where('doctor_id', $id)->first();

            return view('superAdmin.provider.provider_schedule', compact('provider'));
        }
        if ($with == 'finance') {
            if ($provider->based_on == 'commission') {
                $now = Carbon::today();
                $appointments = [];
                for ($i = 0; $i < 7; $i++) {
                    $apt = Appointment::where('doctor_id', $provider->id)->whereDate('created_at', $now)->get();
                    $appointments[] = [
                        'date' => $now->toDateString(),
                        'amount' => $apt->sum('amount'),
                        'admin_commission' => $apt->sum('admin_commission'),
                        'doctor_commission' => $apt->sum('doctor_commission'),
                    ];
                    $now = $now->subDay();
                }
                $past = Carbon::now(env('timezone'))->subDays(35);
                $now = Carbon::today();
                $data = [];
                while ($now->greaterThan($past)) {
                    $t = $past->copy()->addDay();
                    $temp['start'] = $t->toDateString();
                    $past = $past->addDays(10);
                    $temp['end'] = $past->greaterThan($now) ? $now->toDateString() : $past->toDateString();
                    $data[] = $temp;
                }
                $settels = [];
                foreach ($data as $key) {
                    $settle = Settle::where('doctor_id', $provider->id)->where('created_at', '>=', $key['start'].' 00.00.00')->where('created_at', '<=', $key['end'].' 23.59.59')->get();
                    $value['d_total_task'] = $settle->count();
                    $value['admin_earning'] = $settle->sum('admin_amount');
                    $value['doctor_earning'] = $settle->sum('doctor_amount');
                    $value['d_total_amount'] = $value['admin_earning'] + $value['doctor_earning'];
                    $value['duration'] = $key['start'].' - '.$key['end'];
                    $value['d_balance'] = $value['admin_earning'] - $value['doctor_earning'];
                    $settels[] = $value;
                }

                return view('superAdmin.provider.provider_finance', compact('provider', 'currency', 'settels', 'appointments'));
            }
            if ($provider->based_on == 'subscription') {
                $subscriptions = DoctorSubscription::with(['subscription', 'doctor'])->where('doctor_id', $id)->orderBy('id', 'DESC')->get();

                return view('superAdmin.provider.provider_finance', compact('provider', 'currency', 'subscriptions'));
            }
        }
    }

    public function change_status(Request $request)
    {
        abort_if(Gate::denies('provider_edit') || auth()->user()->hasRole('provider'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $provider = Doctor::where('user_role', 'provider')->findOrFail($request->id);
        $provider->update(['status' => $provider->status == 1 ? 0 : 1]);

        return response(['success' => true]);
    }
}
