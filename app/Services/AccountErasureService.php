<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Erases a patient's personal and clinical data when they delete their account. Payment rows (amounts, gateway
 * references, commissions) are kept for accounting, but every field that identifies the patient or describes their
 * health is removed, and their uploaded documents are deleted from disk.
 */
class AccountErasureService
{
    public const DELETED_NAME = 'Deleted User';

    public function erase(User $user): void
    {
        $files = [];

        DB::transaction(function () use ($user, &$files) {
            $id = $user->id;

            foreach (DB::table('appointment')->where('user_id', $id)->get(['id', 'report_image']) as $row) {
                foreach ((array) json_decode((string) $row->report_image, true) as $image) {
                    $files[] = 'images/upload/'.$image;
                }
            }
            DB::table('appointment')->where('user_id', $id)->update([
                'patient_name' => self::DELETED_NAME,
                'age' => 0,
                'phone_no' => '',
                'patient_address' => '',
                'illness_information' => '',
                'note' => '',
                'drug_effect' => '',
                'report_image' => null,
                'cancel_reason' => null,
                'policy_insurer_name' => null,
                'policy_number' => null,
                'zoom_url' => null,
            ]);

            foreach (DB::table('report')->where('user_id', $id)->get(['prescription', 'upload_report']) as $row) {
                $files[] = 'report_prescription/upload/'.$row->prescription;
                $files[] = 'report_prescription/report/'.$row->upload_report;
            }
            DB::table('report')->where('user_id', $id)->update([
                'patient_name' => self::DELETED_NAME,
                'phone_no' => '',
                'age' => 0,
                'gender' => '',
                'prescription' => null,
                'upload_report' => null,
            ]);

            foreach (DB::table('purchase_medicine')->where('user_id', $id)->pluck('pdf') as $pdf) {
                $files[] = 'prescription/upload/'.$pdf;
            }
            DB::table('purchase_medicine')->where('user_id', $id)->update(['pdf' => null]);

            foreach (DB::table('prescription')->where('user_id', $id)->pluck('pdf') as $pdf) {
                $files[] = 'prescription/upload/'.$pdf;
            }
            DB::table('prescription')->where('user_id', $id)->delete();
            DB::table('doctor_notes')->where('user_id', $id)->delete();
            DB::table('review')->where('user_id', $id)->update(['review' => '']);

            DB::table('user_address')->where('user_id', $id)->delete();
            DB::table('faviroute')->where('user_id', $id)->delete();
            DB::table('notification')->where('user_id', $id)->delete();
            DB::table('password_resets')->where('email', $user->email)->delete();
            DB::table('oauth_auth_codes')->where('user_id', $id)->delete();
            DB::table('oauth_access_tokens')->where('user_id', $id)->delete();

            if ($user->image && $user->image !== 'defaultUser.png') {
                $files[] = 'images/upload/'.$user->image;
            }

            $user->forceFill([
                'name' => self::DELETED_NAME,
                'email' => 'deleted-'.$id.'-'.Str::lower(Str::random(12)).'@deleted.invalid',
                'email_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'phone' => '',
                'phone_code' => null,
                'dob' => null,
                'gender' => null,
                'image' => 'defaultUser.png',
                'device_token' => null,
                'channel_name' => null,
                'agora_token' => null,
                'otp' => null,
                'remember_token' => null,
                'verify' => 0,
                'status' => 0,
            ])->save();
        });

        foreach ($files as $file) {
            $name = basename($file);
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $path = public_path(dirname($file).'/'.$name);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
