<?php

namespace App\Http\Controllers;

use App\Services\OtpService;
use App\Http\Controllers\SuperAdmin\CustomController;
use App\Models\Country;
use App\Models\Lab;
use App\Models\LabGallery;
use App\Models\LabSettle;
use App\Models\LabWorkHours;
use App\Models\PayoutAccount;
use App\Models\Pathology;
use App\Models\Radiology;
use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class LabSettingController extends Controller
{
    public function lab_login()
    {
        return view('lab.auth.login');
    }

    public function verify_pathologist(Request $request)
    {
        $request->validate([
            'email' => 'bail|required|email',
            'password' => 'bail|required|min:6',
        ]);

        if (Auth::attempt(['email' => request('email'), 'password' => request('password')])) {
            $pathologist = Auth::user()->load('roles');
            if ($pathologist->hasRole('laboratory')) {
                if ($pathologist->verify == 1) {
                    $lab = Lab::where('user_id', auth()->user()->id)->first();
                    if ($lab->status == 1) {
                        return redirect('pathologist_home');
                    } else {
                        Auth::logout();

                        return redirect()->back()->withErrors('you are disable by admin please contact admin');
                    }
                } else {
                    return redirect('pathologist/send_otp/'.$pathologist->id);
                }
            } else {
                Auth::logout();

                return redirect()->back()->withErrors('Only Pathologist can login');
            }
        } else {
            return redirect()->back()->withErrors('your creditial does not match our record');
        }
    }

    public function pathologist_sign_up()
    {
        $countries = Country::get();

        return view('lab.auth.register', compact('countries'));
    }

    public function verify_sign_up(Request $request)
    {
        $request->validate([
            'user_name' => 'bail|required',
            'lab_name' => 'bail|required',
            'phone_code' => 'bail|required',
            'phone' => 'bail|required|digits_between:6,12',
            'address' => 'bail|required',
            'start_time' => 'bail|required',
            'end_time' => 'bail|required',
            'email' => 'bail|required|email|unique:users',
            'password' => 'bail|required|min:6',
            'image' => 'bail|mimes:jpeg,png,jpg|max:1000',
            'description' => 'bail|nullable|string',
            'gallery_images.*' => 'bail|mimes:jpeg,png,jpg|max:1000',
        ],
            [
                'image.max' => 'The Image May Not Be Greater Than 1 MegaBytes.',
            ]);
        $setting = Setting::first();
        $data = $request->all();
        $veri = $setting->verification == 1 ? 0 : 1;
        $user = User::create([
            'name' => $data['user_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'verify' => $veri,
            'phone' => $data['phone'],
            'phone_code' => $data['phone_code'],
            'image' => 'defaultUser.png',
        ]);
        $user->assignRole('laboratory');
        if ($request->hasFile('image')) {
            $data['image'] = (new CustomController)->imageUpload($request->image);
        } else {
            $data['image'] = 'defaultUser.png';
        }
        $data['start_time'] = strtolower(Carbon::parse($data['start_time'])->format('h:i a'));
        $data['end_time'] = strtolower(Carbon::parse($data['end_time'])->format('h:i a'));
        $data['user_id'] = $user->id;
        $data['name'] = $data['lab_name'];
        $data['status'] = 1;
        $data['commission'] = $setting->pathologist_commission;
        $lab = Lab::create($data);
        if ($request->hasFile('gallery_images')) {
            foreach ($request->file('gallery_images') as $image) {
                LabGallery::create([
                    'lab_id' => $lab->id,
                    'image' => (new CustomController)->imageUpload($image),
                ]);
            }
        }
        $start_time = strtolower($lab->start_time);
        $end_time = strtolower($lab->end_time);
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        for ($i = 0; $i < count($days); $i++) {
            $master = [];
            $temp2['start_time'] = $start_time;
            $temp2['end_time'] = $end_time;
            array_push($master, $temp2);
            $work_time['lab_id'] = $lab->id;
            $work_time['period_list'] = json_encode($master);
            $work_time['day_index'] = $days[$i];
            $work_time['status'] = 1;
            LabWorkHours::create($work_time);
        }
        if ($user->verify == 1) {
            return redirect('/pathologist_home');
        } else {
            return redirect('/pathologist/send_otp/'.$user->id);
        }
    }

    public function send_otp($user_id)
    {
        $user = User::findOrFail($user_id);
        $setting = Setting::first();

        (new CustomController)->sendOtp($user);
        $status = '';
        if ($setting->using_msg == 1 && $setting->using_mail == 1) {
            $status = 'Verification code sent to both email and phone.';
        } elseif ($setting->using_msg == 1) {
            $status = 'Verification code sent to phone.';
        } elseif ($setting->using_mail == 1) {
            $status = 'Verification code sent to email.';
        }

        return view('lab.auth.send_otp', compact('user'))->with('status', $status);
    }

    public function verify_otp(Request $request)
    {
        $otp = OtpService::fromDigits($request->all());
        $user = User::find($request->user_id);
        if ($user && $user->hasRole('laboratory')) {
            if ((new OtpService)->verify($user, $otp)) {
                if (Auth::loginUsingId($user->id)) {
                    return redirect('pathologist_home');
                }
            } else {
                return redirect()->back()->with('error', __('otp does not match'));
            }
        } else {
            return redirect()->back()->with('error', __('Oops.user not found.!'));
        }
    }

    public function pathologist_home()
    {
        $lab = Lab::where('user_id', auth()->user()->id)->first();
        $pathology = Pathology::where('lab_id', $lab->id)->count();
        $radiology = Radiology::where('lab_id', $lab->id)->count();
        $test_reports = Report::where('lab_id', $lab->id)->whereDate('created_at', Carbon::now(env('timezone')))->orderBy('id', 'DESC')->get();
        $currency = Setting::first()->currency_symbol;

        return view('lab.home', compact('pathology', 'radiology', 'test_reports', 'currency'));
    }

    public function lab_commission()
    {
        abort_if(Gate::denies('lab_commission'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $lab = Lab::where('user_id', auth()->user()->id)->first();
        $now = Carbon::today(env('timezone'));
        $currency = Setting::first()->currency_symbol;
        $past = Carbon::now(env('timezone'))->subDays(35);
        $now = Carbon::today(env('timezone'));
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
            $settle = LabSettle::where('lab_id', $lab->id)->where('created_at', '>=', $key['start'].' 00.00.00')->where('created_at', '<=', $key['end'].' 23.59.59')->get();
            $value['d_total_task'] = $settle->count();
            $value['admin_earning'] = $settle->sum('admin_amount');
            $value['lab_amount'] = $settle->sum('lab_amount');
            $value['d_total_amount'] = $value['admin_earning'] + $value['lab_amount'];
            $remainingOnline = LabSettle::where([['lab_id', $lab->id], ['payment', 0], ['lab_status', 0]])->where('created_at', '>=', $key['start'].' 00.00.00')->where('created_at', '<=', $key['end'].' 23.59.59')->get();
            $remainingOffline = LabSettle::where([['lab_id', $lab->id], ['payment', 1], ['lab_status', 0]])->where('created_at', '>=', $key['start'].' 00.00.00')->where('created_at', '<=', $key['end'].' 23.59.59')->get();

            $online = $remainingOnline->sum('lab_amount'); // admin e devana
            $offline = $remainingOffline->sum('admin_amount'); // admin e levana

            $value['duration'] = $key['start'].' - '.$key['end'];
            $value['d_balance'] = $offline - $online; // + hoy to levana - devana
            array_push($settels, $value);
        }

        return view('superAdmin.lab.commission', compact('lab', 'currency', 'settels'));
    }

    public function show_lab_settalement(Request $request)
    {
        $duration = explode(' - ', $request->duration);
        $currency = Setting::first()->currency_symbol;
        $settle = LabSettle::where('created_at', '>=', $duration[0].' 00.00.00')->where('created_at', '<=', $duration[1].' 23.59.59')->get();
        foreach ($settle as $s) {
            $s->date = $s->created_at->toDateString();
        }

        return response(['success' => true, 'data' => $settle, 'currency' => $currency]);
    }

    public function lab_timeslot()
    {
        $lab = Lab::where('user_id', auth()->user()->id)->first();
        $lab->workingHours = LabWorkHours::where('lab_id', $lab->id)->get();
        $lab->firstHours = LabWorkHours::where('lab_id', $lab->id)->first();

        return view('lab.schedule', compact('lab'));
    }

    public function display_lab_timeslot($id)
    {
        $work = LabWorkHours::find($id);

        return response(['success' => true, 'data' => $work]);
    }

    public function edit_lab_timeslot($id)
    {
        $work = LabWorkHours::find($id);

        return response(['success' => true, 'data' => $work]);
    }

    public function update_lab_timeslot(Request $request)
    {
        $data = $request->all();
        $work = LabWorkHours::find($request->working_id);
        $master = [];
        for ($i = 0; $i < count($request->start_time); $i++) {
            $temp['start_time'] = strtolower($request->start_time[$i]);
            $temp['end_time'] = strtolower($request->end_time[$i]);
            array_push($master, $temp);
        }
        $data['period_list'] = json_encode($master);
        $data['status'] = $request->has('status') ? 1 : 0;
        $work->update($data);

        return redirect()->back();
    }

    public function change_lab_payment_status($id)
    {
        $query = Report::query();
        if (auth()->user()->hasRole('laboratory')) {
            $query->where('lab_id', Lab::where('user_id', auth()->id())->value('id'));
        } else {
            abort_if(Gate::denies('test_report'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        }
        $query->findOrFail($id)->update(['payment_status' => 1]);

        return ['success' => true];
    }

    public function lab_profile()
    {
        $lab = Lab::where('user_id', auth()->user()->id)->first();
        $lab['start_time'] = strtolower(Carbon::parse($lab['start_time'])->format('H:i'));
        $lab['end_time'] = strtolower(Carbon::parse($lab['end_time'])->format('H:i'));

        return view('superAdmin.lab.edit_lab',compact('lab'));
    }

    // ─── Withdrawal ──────────────────────────────────────────────────────────

    private function labAvailableBalance(Lab $lab): float
    {
        $totalEarned = (float) LabSettle::where('lab_id', $lab->id)->sum('lab_amount');
        $reserved = (float) WithdrawalRequest::where('holder_type', WithdrawalRequest::HOLDER_LAB)
            ->where('holder_id', $lab->id)
            ->whereIn('status', [
                WithdrawalRequest::STATUS_PENDING,
                WithdrawalRequest::STATUS_APPROVED,
                WithdrawalRequest::STATUS_PAID,
            ])
            ->sum('amount');
        return max(0.0, $totalEarned - $reserved);
    }

    public function withdrawalIndex()
    {
        $lab = Lab::where('user_id', auth()->user()->id)->first();
        $currency = Setting::first()->currency_symbol;
        $availableBalance = $this->labAvailableBalance($lab);
        $payoutAccount = PayoutAccount::where('holder_type', PayoutAccount::HOLDER_LAB)
            ->where('holder_id', $lab->id)->first();
        $withdrawals = WithdrawalRequest::where('holder_type', WithdrawalRequest::HOLDER_LAB)
            ->where('holder_id', $lab->id)
            ->orderByDesc('created_at')
            ->get();
        return view('lab.withdrawal', compact('lab', 'currency', 'availableBalance', 'payoutAccount', 'withdrawals'));
    }

    public function withdrawalStore(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:1']);
        $lab = Lab::where('user_id', auth()->user()->id)->first();
        $payoutAccount = PayoutAccount::where('holder_type', PayoutAccount::HOLDER_LAB)
            ->where('holder_id', $lab->id)->first();
        if (! $payoutAccount) {
            return redirect()->back()->with('error', 'Please add your payout account before requesting a withdrawal.');
        }
        $available = $this->labAvailableBalance($lab);
        if ((float) $request->amount > $available) {
            return redirect()->back()->with('error', 'Requested amount exceeds your available balance.');
        }
        WithdrawalRequest::create([
            'holder_type'           => WithdrawalRequest::HOLDER_LAB,
            'holder_id'             => $lab->id,
            'amount'                => $request->amount,
            'payout_account_name'   => $payoutAccount->account_name,
            'payout_account_number' => $payoutAccount->account_number,
            'payout_bank_name'      => $payoutAccount->bank_name,
            'status'                => WithdrawalRequest::STATUS_PENDING,
        ]);
        return redirect()->back()->with('status', 'Withdrawal request submitted successfully.');
    }

    public function payoutAccountUpdate(Request $request)
    {
        $request->validate([
            'account_name'   => 'required|string|max:255',
            'account_number' => 'required|string|max:255',
            'bank_name'      => 'required|string|max:255',
        ]);
        $lab = Lab::where('user_id', auth()->user()->id)->first();
        PayoutAccount::updateOrCreate(
            ['holder_type' => PayoutAccount::HOLDER_LAB, 'holder_id' => $lab->id],
            ['account_name' => $request->account_name, 'account_number' => $request->account_number, 'bank_name' => $request->bank_name]
        );
        return redirect()->back()->with('status', 'Payout account saved.');
    }

    // ─────────────────────────────────────────────────────────────────────────
}
