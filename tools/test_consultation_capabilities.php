<?php

// No production database, application secrets or network access.
namespace App\Models {
    class Doctor {
        public ?string $user_role = null;
        public $offers_video_call = null;
        public $offers_home_visit = null;
    }
}

namespace {
    require __DIR__.'/../app/Services/ConsultationCapabilities.php';

    use App\Models\Doctor;
    use App\Services\ConsultationCapabilities as Policy;

    $doctor = new Doctor;
    $doctor->user_role = 'doctor';
    $doctor->offers_video_call = 1;
    $doctor->offers_home_visit = 1; // Legacy bad database flag.
    assert(Policy::allowedVisitTypes($doctor) === ['video_call']);
    assert(! Policy::canBook($doctor, 'home_visit'));

    $provider = new Doctor;
    $provider->user_role = 'provider';
    $provider->offers_home_visit = 1;
    $provider->offers_video_call = 0;
    assert(Policy::allowedVisitTypes($provider) === ['home_visit']);
    assert(! Policy::canBook($provider, 'video_call'));

    $provider->offers_video_call = 1;
    assert(Policy::allowedVisitTypes($provider) === ['video_call', 'home_visit']);
    assert(Policy::canBook($provider, 'video_call'));
    assert(Policy::canBook($provider, 'home_visit'));

    $provider->offers_home_visit = 0;
    assert(Policy::allowedVisitTypes($provider) === ['video_call']);
    assert(! Policy::canBook($provider, 'home_visit'));

    $files = [
        'UserApiController.php' => __DIR__.'/../app/Http/Controllers/UserApiController.php',
        'DoctorApiController.php' => __DIR__.'/../app/Http/Controllers/DoctorApiController.php',
    ];
    $user = file_get_contents($files['UserApiController.php']);
    $professional = file_get_contents($files['DoctorApiController.php']);
    assert(str_contains($user, 'ConsultationCapabilities::canBook($doctor, $visitType)'));
    assert(str_contains($user, "hasOpenBooking(auth()->id(), $doctor->id, 'video_call')"));
    assert(str_contains($professional, "hasOpenBooking((int) $request->to_id, $doctor->id, 'video_call')"));
    assert(str_contains($professional, "Prescription access requires an approved doctor account."));
    assert(! str_contains($user, 'info($data);'));

    echo "PASS consultation roles, visit types, audio/video authorization boundaries, and prescribing guard.\n";
}
