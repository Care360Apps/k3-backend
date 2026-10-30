<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function user_report(Request $request)
    {
        abort_if(Gate::denies('report_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        (new CustomController)->cancel_max_order();
        $currency = Setting::first()->currency_symbol;
        if ($request->has('update_start_end_date')) {
            $date = explode(' - ', $request->update_start_end_date);
            $users = User::doesntHave('roles')->whereBetween('created_at', [$date[0], $date[1]])->orderBy('id', 'DESC')->get();
        } else {
            $users = User::doesntHave('roles')->orderBy('id', 'DESC')->get();
        }
        foreach ($users as $user) {
            $user->totalBooking = Appointment::where('user_id', $user->id)->count();
            $user->RemaingAmount = Appointment::where([['user_id', $user->id], ['payment_status', 0]])->sum('amount');
        }
        $activeUser = User::doesntHave('roles')->where('status', 1)->count();
        $blockUser = User::doesntHave('roles')->where('status', 0)->count();

        return view('superAdmin.report.user_report', compact('users', 'currency', 'activeUser', 'blockUser'));
    }

    public function doctor_report()
    {
        abort_if(Gate::denies('report_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        (new CustomController)->cancel_max_order();
        $currency = Setting::first()->currency_symbol;
        $doctors = Doctor::orderBy('id', 'DESC')->get();
        foreach ($doctors as $doctor) {
            $doctor->user = User::where('id', $doctor->user_id)->first();
            $doctor->totalOrder = Appointment::where('doctor_id', $doctor->id)->count();
        }
        $subscriptionDoctor = Doctor::where('based_on', 'subscription')->count();
        $commissionDoctor = Doctor::where('based_on', 'commission')->count();

        return view('superAdmin.report.doctor_report', compact('doctors','subscriptionDoctor','commissionDoctor'));
    }
}
