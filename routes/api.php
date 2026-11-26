<?php
// Public, reviewed-only facility directory (no merchant account creation).
Route::get('directory/v1/facilities', [\App\Http\Controllers\FacilityDirectoryController::class, 'index'])->middleware('throttle:60,1');
Route::get('directory/v1/facilities/{id}', [\App\Http\Controllers\FacilityDirectoryController::class, 'show'])->whereNumber('id')->middleware('throttle:60,1');


use App\Http\Controllers\DoctorApiController;
use App\Http\Controllers\DoctorNoteController;
use App\Http\Controllers\PaymentApiController;
use App\Http\Controllers\PatientPlaceSearchController;
use App\Http\Controllers\Care360AiGuideController;
use App\Http\Controllers\WorkforcePilotController;
use App\Http\Middleware\WorkforcePilotEnabled;
use App\Http\Middleware\AuthenticateWorkforce;
use App\Http\Middleware\EnsureApprovedProfessional;
use App\Http\Controllers\PatientMedicalFileController;
use App\Http\Controllers\ProviderApiController;
use App\Http\Controllers\NearbyCareController;
use App\Http\Controllers\UserApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// A browser return is not payment confirmation. Orders still require server verification.
Route::get('paystack/return', fn () => response()->json(['message' => 'Return to Care360 to verify your payment.'])
    ->header('Cache-Control', 'no-store'))->middleware('throttle:30,1');

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Opt-in consultation session API; legacy clients remain unchanged.
Route::prefix('consultations/v1')->middleware(['auth:api', 'throttle:12,1'])->group(function () {
    Route::post('{appointmentId}/credentials', [\App\Http\Controllers\ConsultationSessionController::class, 'credentials'])->whereNumber('appointmentId');
    Route::get('{appointmentId}/status', [\App\Http\Controllers\ConsultationSessionController::class, 'status'])->whereNumber('appointmentId');
    Route::post('{appointmentId}/end', [\App\Http\Controllers\ConsultationSessionController::class, 'end'])->whereNumber('appointmentId');
});

Route::middleware(['auth:api'])->get('/user', function (Request $request) {
    return $request->user();
});

// Isolated nonclinical AI navigation (default OFF) and rate-limited.
Route::post('/ai/guide', [Care360AiGuideController::class, 'guide'])
    ->middleware(['auth:api', 'throttle:5,1']);

// A separate recruitment authorization boundary. These tokens NEVER use
// Passport's clinical auth:api guard and cannot access appointments/records.
// Entire feature defaults OFF until security and credential reviews complete.
Route::middleware([\App\Http\Middleware\CareerSafeErrors::class])->group(function(){
Route::get('/workforce/v1/status', [WorkforcePilotController::class, 'status'])
    ->middleware('throttle:15,1,workforce-status');
// Existing identities retain privacy access even when new registrations close.
Route::post('workforce/v1/login',[WorkforcePilotController::class,'login'])->middleware('throttle:5,1,workforce-login');
Route::prefix('workforce/v1')->middleware([WorkforcePilotEnabled::class])->group(function () {
    Route::post('register', [WorkforcePilotController::class, 'register'])
        ->middleware('throttle:5,1,workforce-register');

    Route::middleware([AuthenticateWorkforce::class])->group(function () {
        Route::get('me', [WorkforcePilotController::class, 'me']);
        Route::post('consent',[WorkforcePilotController::class,'consent'])->middleware('throttle:5,1,career-consent');
        Route::post('candidate/profile', [WorkforcePilotController::class, 'candidateProfile'])
            ->middleware(['throttle:20,1,workforce-profile',\App\Http\Middleware\CareerNoticeConsent::class]);
        Route::post('employer/profile', [WorkforcePilotController::class, 'employerProfile'])
            ->middleware(['throttle:20,1,workforce-profile',\App\Http\Middleware\CareerNoticeConsent::class]);
        Route::get('candidates', [WorkforcePilotController::class, 'candidates'])
            ->middleware(['throttle:20,1,workforce-profile',\App\Http\Middleware\CareerNoticeConsent::class]);
        Route::post('logout', [WorkforcePilotController::class, 'logout']);
    });
});

});

// Onesignal Notification Test, Only for Developers.
// Route::get('/send-test-notification', [DoctorApiController::class, 'sendTestNotification']);

Route::middleware('throttle:auth')->group(function () {
    Route::post('/login', [UserApiController::class, 'apiLogin']);
    Route::post('/register', [UserApiController::class, 'apiRegister']);
    Route::post('/check_otp', [UserApiController::class, 'apiCheckOtp']);
    Route::get('/resendOtp/{user_id}', [UserApiController::class, 'apiResendOtp']);
    Route::post('/forgot_password', [UserApiController::class, 'apiForgotPassword']);
    Route::post('doctor_login', [DoctorApiController::class, 'apiDoctorLogin']);
    Route::post('doctor_register', [DoctorApiController::class, 'apiDoctorRegister']);
    Route::post('provider_login', [ProviderApiController::class, 'apiProviderLogin']);
    Route::post('provider_register', [ProviderApiController::class, 'apiProviderRegister']);
});
Route::post('/doctors', [UserApiController::class, 'apiDoctors']);
Route::get('/treatments', [UserApiController::class, 'apiTreatments']);
Route::get('/offers', [UserApiController::class, 'apiOffers']);
Route::get('/courses', [UserApiController::class, 'apiCourses'])->middleware(['auth:api','throttle:60,1']);
Route::get('course-images/{filename}', [\App\Http\Controllers\CourseImageController::class,'show'])->middleware('throttle:120,1');
Route::post('near_by_doctor', [UserApiController::class, 'apiNearByDoctor']);
Route::post('doctor_details/{id}', [UserApiController::class, 'apiSingleDoctor']);
Route::get('provider_types', [ProviderApiController::class, 'apiProviderTypes']);
Route::post('providers', [UserApiController::class, 'apiProviders']);
Route::post('nearby-care', [NearbyCareController::class, 'nearby'])
    ->middleware('throttle:30,1');
Route::post('provider_details/{id}', [UserApiController::class, 'apiSingleProvider']);
Route::post('/timeslot', [UserApiController::class, 'apiTimeslot']);
Route::get('/setting', [UserApiController::class, 'apiSetting']);
Route::get('/blogs', [UserApiController::class, 'apiBlogs']);
Route::get('/blog_details/{id}', [UserApiController::class, 'apiSingleBlog']);
Route::get('/pharamacies', [UserApiController::class, 'apipharmacy']);
Route::get('/pharmacy_details/{id}', [UserApiController::class, 'apiSinglepharmacy']);
Route::get('/medicine_details/{id}', [UserApiController::class, 'apiSingleMedicine']);
Route::get('/labs', [UserApiController::class, 'apiLabs']);
Route::get('/lab_details/{id}', [UserApiController::class, 'apiLabDetails']);
Route::get('/public_hospitals', [UserApiController::class, 'apiHospitals']);
Route::get('/public_hospital_details/{id}', [UserApiController::class, 'apiHospitalDetails']);
Route::get('/lab_pathology/{lab_id}', [UserApiController::class, 'apiLabPathology']);
Route::get('/lab_radiology/{lab_id}', [UserApiController::class, 'apiLabRadiology']);
Route::post('treatment_wise_doctor/{treatment_id}', [UserApiController::class, 'apiTreatmentDoctor']);
Route::get('/banner', [UserApiController::class, 'apiBanner']);
Route::get('banner-images/{id}', [\App\Http\Controllers\BannerImageController::class, 'show'])->whereNumber('id')->middleware('throttle:120,1');

Route::middleware('auth:api')->group(function () {
    Route::post('stripe/payment_intent', [PaymentApiController::class, 'stripePaymentIntent']);
    Route::post('paypal/create_payment', [PaymentApiController::class, 'paypalCreatePayment']);
    Route::post('paypal/execute_payment', [PaymentApiController::class, 'paypalExecutePayment']);
});

Route::middleware('auth:api')->group(function () {
    Route::post('/refresh_token', [UserApiController::class, 'apiRefreshToken']);
    Route::post('/book_appointment', [UserApiController::class, 'apiBooking']);
    Route::get('appointments', [UserApiController::class, 'apiAppointments']);
    // Shared by both apps: answers doctors itself and hands patients to UserApiController::apiSingleAppointment.
    Route::get('appointment_details/{id}', [DoctorApiController::class, 'apiSingleAppointment']);
    Route::post('complete_appointment', [UserApiController::class, 'apiCompleteAppointment']);
    Route::get('prescription/{appointment_id}', [UserApiController::class, 'apiAppointmentPrescription']);
    // Authenticated private-storage-only route; legacy public URLs remain
    // a separate migration/release blocker until every client is updated.
    Route::get('medical-files/appointments/{appointmentId}/prescription',
        [PatientMedicalFileController::class, 'downloadPrescription'])
        ->whereNumber('appointmentId')
        ->middleware('throttle:30,1');

    Route::post('update_profile', [UserApiController::class, 'apiUpdateProfile']);
    Route::post('update_image', [UserApiController::class, 'apiUpdateImage']);
    Route::post('book_medicine', [UserApiController::class, 'apiBookMedicine']);
    Route::get('medicines', [UserApiController::class, 'apiMedicines']);
    Route::get('single_medicine/{purchase_medicide_id}', [UserApiController::class, 'apiSingleMedicineDetails']);
    Route::post('lab_timeslot', [UserApiController::class, 'apiLabTimeslot']);
    Route::post('lab_book', [UserApiController::class, 'apiLabBook']);
    Route::post('cancel_appointment', [UserApiController::class, 'apiCancelAppointment']);
    Route::get('address', [UserApiController::class, 'apiShowAddress']);
    Route::post('add_address', [UserApiController::class, 'apiAddAddress']);
    // The caller's OAuth/API token stays on Care360; Google key is server-only.
    Route::post('places/autocomplete', [PatientPlaceSearchController::class, 'autocomplete'])->middleware('throttle:20,1');
    Route::post('places/details', [PatientPlaceSearchController::class, 'details'])->middleware('throttle:20,1');
    Route::get('delete_address/{id}', [UserApiController::class, 'apiDeleteAddress']);
    Route::post('add_review', [UserApiController::class, 'apiAddReview']);
    Route::post('check_offer', [UserApiController::class, 'apiCheckCoupon']);
    Route::get('user_notification', [UserApiController::class, 'apiUserNotification']);
    Route::get('add_bookmark/{doctor_id}', [UserApiController::class, 'apiAddBookmark']);
    Route::get('faviroute_doctor', [UserApiController::class, 'apiFaviroute']);
    Route::post('/generateAgoraToken', [UserApiController::class, 'apiGenerateToken'])->middleware('throttle:6,1,agora-issuance');
    Route::get('/video_call_history', [UserApiController::class, 'apiVideoCallHistory']);
    Route::post('/add_call_history', [UserApiController::class, 'apiAddHistory']);
    Route::get('/send_notification', [UserApiController::class, 'apiSendNotification']);
    // The new Patient client uses a deliberate authenticated POST request.
    // Existing published clients still call the legacy GET endpoint; retire
    // GET only after installed-app migration and full account-deletion QA.
    Route::post('/delete-account', [UserApiController::class, 'deleteAccount'])
        ->middleware('throttle:3,10');
    Route::get('/delete-account', [UserApiController::class, 'deleteAccount'])
        ->middleware('throttle:3,10');
    Route::get('/insurers', [UserApiController::class, 'insurers']);
    Route::get('/logout', [UserApiController::class, 'apiLogout']);
    Route::post('/send_message_to_doctor_notification', [UserApiController::class, 'apiSendMessageToDoctorNotificationTrigger'])->middleware('throttle:10,1');
});
// Route::get('/send_notification',[UserApiController::class,'apiSendNotification']);

// ************* DOCTOR / PROVIDER *******************//
Route::get('allMedicines', [DoctorApiController::class, 'apiMedicines']);

Route::middleware('auth:api')->group(function () {
    Route::get('doctor_appointment', [DoctorApiController::class, 'apiDoctorAppointment'])->middleware(EnsureApprovedProfessional::class);
    Route::get('doctor_notes', [DoctorNoteController::class, 'index'])->middleware(EnsureApprovedProfessional::class);
    Route::post('doctor_notes', [DoctorNoteController::class, 'store'])->middleware(EnsureApprovedProfessional::class);
    Route::put('doctor_notes/{id}', [DoctorNoteController::class, 'update'])->middleware(EnsureApprovedProfessional::class);
    Route::delete('doctor_notes/{id}', [DoctorNoteController::class, 'destroy'])->middleware(EnsureApprovedProfessional::class);
    Route::post('add_prescription', [DoctorApiController::class, 'apiAddPrescription'])->middleware(EnsureApprovedProfessional::class);
    Route::get('working_hours', [DoctorApiController::class, 'apiWorkingHours']);
    Route::post('update_time', [DoctorApiController::class, 'apiUpdateWorkingHours']);
    Route::get('doctor_profile', [DoctorApiController::class, 'apiLoginDoctor']);
    // Professional-only opt-in availability, never derived from device GPS
    // automatically; each on-duty shift expires without renewal.
    Route::get('professional/presence', [NearbyCareController::class, 'myStatus'])
        ->middleware('throttle:30,1');
    Route::post('professional/presence', [NearbyCareController::class, 'update'])
        ->middleware('throttle:10,1');
    Route::post('update_doctor', [DoctorApiController::class, 'apiUpdateDoctor']);
    Route::get('doctor_review', [DoctorApiController::class, 'apiDoctorReview']);
    Route::get('treatment', [DoctorApiController::class, 'apiTreatment']);
    Route::get('categories/{treatment_id}', [DoctorApiController::class, 'apiCategory']);
    Route::get('expertise/{caegory_id}', [DoctorApiController::class, 'apiExpertise']);
    Route::get('hospitals', [DoctorApiController::class, 'apiHospital']);
    Route::post('status_change', [DoctorApiController::class, 'apiStatusChange'])->middleware(EnsureApprovedProfessional::class);
    Route::post('updatePatientVcall', [DoctorApiController::class, 'apiUpdatePatientVCallSwitch'])->middleware(EnsureApprovedProfessional::class);
    Route::get('appointment_history', [DoctorApiController::class, 'apiAppointmentHistory'])->middleware(EnsureApprovedProfessional::class);
    Route::get('payment', [DoctorApiController::class, 'apiPayment'])->middleware(EnsureApprovedProfessional::class);
    Route::post('payment', [DoctorApiController::class, 'apiPayment'])->middleware(EnsureApprovedProfessional::class);
    Route::get('subscription', [DoctorApiController::class, 'apiSubscription']);
    Route::get('finance_details', [DoctorApiController::class, 'apiFinanceDetails'])->middleware(EnsureApprovedProfessional::class);
    Route::get('withdrawal_requests', [DoctorApiController::class, 'apiWithdrawalRequests'])->middleware(EnsureApprovedProfessional::class);
    Route::post('withdrawal_requests', [DoctorApiController::class, 'apiWithdrawalRequestStore'])->middleware(EnsureApprovedProfessional::class);
    Route::get('payout_account', [DoctorApiController::class, 'apiPayoutAccountShow'])->middleware(EnsureApprovedProfessional::class);
    Route::post('payout_account', [DoctorApiController::class, 'apiPayoutAccountUpdate'])->middleware(EnsureApprovedProfessional::class);
    Route::get('notification', [DoctorApiController::class, 'apiNotification'])->middleware(EnsureApprovedProfessional::class);
    Route::post('purchase_subscrption', [DoctorApiController::class, 'apiPurchaseSubscription']);
    Route::post('paystack_init', [DoctorApiController::class, 'apiPaystackInit']);
    Route::post('doctor_update_image', [DoctorApiController::class, 'apiUpdateImage']);
    Route::get('cancel_appointment', [DoctorApiController::class, 'apiCancelAppointment'])->middleware(EnsureApprovedProfessional::class);
    Route::post('doctor_change_password', [DoctorApiController::class, 'apiDoctorChangePassword']);
    Route::post('/generateDoctorAgoraToken',[DoctorApiController::class, 'apiDoctorGenerateToken'])->middleware([EnsureApprovedProfessional::class, 'throttle:6,1,agora-issuance']);
    Route::post('/send_message_to_patient_notification', [DoctorApiController::class, 'apiSendMessageToPatientNotificationTrigger'])->middleware('throttle:10,1')->middleware(EnsureApprovedProfessional::class);
});

Route::middleware('auth:api')->group(function () {
    Route::get('patient/lab-results', [\App\Http\Controllers\PatientLabResultController::class,'index']);
    Route::get('patient/lab-results/{reportId}/file', [\App\Http\Controllers\PatientLabResultController::class,'download'])->whereNumber('reportId');
});

Route::prefix('clinical')->middleware(['auth:api', EnsureApprovedProfessional::class])->group(function () {
    $controller = \App\Http\Controllers\ClinicalWorkflowController::class;
    Route::get('encounters', [$controller,'encounters'])->middleware('throttle:60,1');
    Route::get('appointments/{id}', [$controller,'workspace'])->whereNumber('id');
    Route::post('appointments/{id}/observations', [$controller,'observe'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('appointments/{id}/orders', [$controller,'order'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('orders/{id}/status', [$controller,'transition'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('orders/{id}/result', [$controller,'result'])->whereNumber('id');
    Route::get('critical-results', [$controller,'criticalInbox']);
    Route::post('critical-results/{id}/acknowledge', [$controller,'acknowledge'])->whereNumber('id')->middleware('throttle:30,1');
});

// Career and CNU training workflows have independent rollout and account guards.
require __DIR__.'/career.php';

Route::prefix('wallet/v1')->middleware(['auth:api', 'throttle:30,1'])->group(function () {
    Route::get('balance', [\App\Http\Controllers\WalletController::class, 'balance']);
    Route::get('history', [\App\Http\Controllers\WalletController::class, 'history']);
});
