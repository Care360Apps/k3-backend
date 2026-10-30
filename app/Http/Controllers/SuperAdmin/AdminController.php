<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\Controller;
use App\Mail\SendMail;
use App\Mail\TestMail;
use App\Models\Language;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\User;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends Controller
{
    public function admin_login()
    {
        if (env('DB_DATABASE') == '') {
            return view('first_page');
        } elseif (auth()->check()) {
            return redirect('home');
        }

        return view('superAdmin.auth.login');
    }

    public function loginAsDoctor($id)
    {
        $this->authorizeSuperAdmin();
        $user = User::find($id);
        if (! $user || ! $user->hasAnyRole(['doctor', 'provider'])) {
            return redirect()->back();
        }
        Auth::logout();
        Auth::login($user);

        return redirect('doctor_home');
    }

    public function loginAsPatient($id)
    {
        $this->authorizeSuperAdmin();
        $user = User::find($id);
        if (! $user || $user->roles()->exists()) {
            return redirect()->back();
        }
        Auth::logout();
        Auth::login($user);

        return redirect('/');
    }

    // Doctor and Patient logout
    public function logoutUser($id)
    {
        $this->authorizeSuperAdmin();
        $user = User::findOrFail($id);
        $user->update(['device_token' => null]);
        $user->tokens()->delete();

        return redirect()->back()->withStatus(__('User logged out successfully'));
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole('super admin'), Response::HTTP_FORBIDDEN, '403 Forbidden');
    }

    public function verify_admin(Request $request)
    {
        $request->validate([
            'email' => 'bail|required|email',
            'password' => 'bail|required|min:6',
        ]);

        if (Auth::attempt(['email' => request('email'), 'password' => request('password')])) {
            $user = Auth::user()->load('roles');
            if ($user->hasRole('super admin') && $user->id == 1) {
                return redirect('home');
            } else {
                if ($user->status == 1) {
                    return redirect('profile');
                } else {
                    Auth::logout();

                    return redirect()->back()->withErrors('Block By Admin. Please Contact support.');
                }
            }
        } else {
            return redirect()->back()->withErrors('your credential does not match our record');
        }
    }

    public function installer()
    {
        abort_if($this->isInstalled(), Response::HTTP_NOT_FOUND);

        return view('superAdmin.license.install');
    }

    private function isInstalled(): bool
    {
        return filled(config('database.connections.'.config('database.default').'.database'));
    }

    public function admin_forgot_password()
    {
        $from = 'super admin';

        return view('superAdmin.admin.forgot_password', compact('from'));
    }

    public function doctor_forgot_password()
    {
        $from = 'doctor';

        return view('superAdmin.admin.forgot_password', compact('from'));
    }

    public function pharmacy_forgot_password()
    {
        $from = 'pharmacy';

        return view('superAdmin.admin.forgot_password', compact('from'));
    }

    public function lab_forgot_password()
    {
        $from = 'lab';

        return view('superAdmin.admin.forgot_password', compact('from'));
    }

    public function send_forgot_password(Request $request)
    {
        $request->validate(['email' => 'bail|required|email']);
        PasswordResetController::sendLink($request->email);

        return redirect()->back()->with('status', __(PasswordResetController::GENERIC_MESSAGE));
    }

    public function profile()
    {
        $user = auth()->user();

        return view('superAdmin.admin.admin', compact('user'));
    }

    public function update_profile(Request $request)
    {
        $request->validate(
            [
                'name' => 'bail|required',
                'image' => 'bail|max:1000',
            ],
            [
                'image.max' => 'The Image May Not Be Greater Than 1 MegaBytes.',
            ]
        );
        $user = auth()->user();
        $data = $request->all();
        if ($request->hasFile('image')) {
            (new CustomController)->deleteFile($user->image);
            $data['image'] = (new CustomController)->imageUpload($request->image);
        }
        $user->update($data);

        return redirect()->back()->withStatus(__('admin profile update successfully'));
    }

    public function change_password(Request $request)
    {
        $request->validate([
            'old_password' => 'bail|required|min:6',
            'new_password' => 'bail|required|min:6',
            'confirm_new_password' => 'bail|required|min:6|same:new_password',
        ]);
        $data = $request->all();
        $id = auth()->user();
        if (Hash::check($data['old_password'], $id->password) == true) {
            $id->password = Hash::make($data['new_password']);
            $id->save();

            return redirect()->back()->withStatus(__('Password Changed Successfully.'));
        } else {
            return redirect()->back()->with('error', 'old password does not match');
        }
    }

    public function saveEnvData(Request $request)
    {
        abort_if($this->isInstalled(), Response::HTTP_NOT_FOUND);
        $request->validate([
            'email' => 'bail|required|email',
            'password' => 'bail|required|min:6',
            'db_host' => ['required', 'regex:/^[^\s"#\\\\]+$/'],
            'db_name' => ['required', 'regex:/^[^\s"#\\\\]+$/'],
            'db_user' => ['required', 'regex:/^[^\s"#\\\\]+$/'],
            'db_pass' => ['nullable', 'regex:/^[^\r\n\'\\\\]*$/'],
        ]);
        $envdata['DB_HOST'] = $request->db_host;
        $envdata['DB_DATABASE'] = $request->db_name;
        $envdata['DB_USERNAME'] = $request->db_user;
        $envdata['DB_PASSWORD'] = "'".$request->db_pass."'";
        $result = (new CustomController)->updateENV($envdata);
        if ($result) {
            session()->put('installer_env_saved', true);
            // Artisan::call('config:clear');
            // Artisan::call('optimize:clear');
            // Artisan::call('cache:clear');
            return response()->json(['success' => true], 200);
        } else {
            return response()->json(['success' => false, 'message' => 'Don\'t have enough permission for .env file to be written. '], 200);
        }
    }

    public function saveAdminData(Request $request)
    {
        abort_unless(session()->pull('installer_env_saved'), Response::HTTP_NOT_FOUND);
        $request->validate([
            'email' => 'bail|required|email',
            'password' => 'bail|required|min:6',
        ]);
        User::first()->update(['email' => $request->email, 'password' => Hash::make($request->password)]);
        Setting::find(1)->update(['license_code' => $request->license_code, 'client_name' => $request->client_name, 'license_verify' => 1]);

        return response()->json(['data' => url('/login'), 'success' => true], 200);
    }

    public function changeLanguage($id)
    {
        $language = Language::find($id);
        App::setLocale($language->name);
        session()->put('locale', $language->name);
        $direction = $language->direction;
        session()->put('direction', $direction);
        if (Auth::check()) {
            $user = auth()->user();
            $user->language = $language->name;
            $user->save();
        }

        return redirect()->back();
    }

    public function testMail(Request $request)
    {
        abort_if(Gate::denies('superadmin_setting'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        try {
            $setting = Setting::first();
            $subject = 'Test Mail From Admin Panel';
            $message = 'This is a test email sent from the admin panel to ensure the proper configuration';
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
            Mail::to($request->to)->send(new TestMail($message, $subject, $setting->business_name));

            return response()->json(['success' => true, 'message' => 'Mail Sent Successfully!'], 200);
        } catch (\Exception $e) {
            $error = $e->getMessage();

            return response()->json(['success' => false, 'message' => $error]);
        }
    }

    public function logoutAllDoctors()
    {
        $this->authorizeSuperAdmin();
        $doctors = User::select('id', 'device_token')->whereHas('roles', function ($query) {
            $query->where('name', 'doctor');
        })->get();
        foreach ($doctors as $doctor) {
            $doctor->update(['device_token' => null]);
            $doctor->tokens()->delete();
        }

        return redirect()->back()->withStatus(__('All doctors logged out successfully'));
    }

    public function logoutAllPatients()
    {
        $this->authorizeSuperAdmin();
        $patients = User::select('id', 'device_token')->doesntHave('roles')->get();
        foreach ($patients as $patient) {
            $patient->update(['device_token' => null]);
            $patient->tokens()->delete();
        }

        return redirect()->back()->withStatus(__('All patients logged out successfully'));
    }
}
