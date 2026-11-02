<?php

use App\Http\Controllers\DoctorApiController;
use App\Http\Controllers\Care360AiGuideController;
use App\Http\Controllers\PaymentApiController;
use App\Http\Controllers\ProviderApiController;
use App\Http\Controllers\UserApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

Route::middleware(['auth:api'])->get('/user', function (Request $request) {
    return $request->user();
});

// Opt-in, authenticated app-navigation help. No free-text medical prompts.
Route::post('/ai/guide', [Care360AiGuideController::class, 'guide'])
    ->middleware(['auth:api', 'throttle:5,1']);

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
Route::get('/courses', [UserApiController::class, 'apiCourses']);
Route::post('near_by_doctor', [UserApiController::class, 'apiNearByDoctor']);
Route::post('doctor_details/{id}', [UserApiController::class, 'apiSingleDoctor']);
Route::get('provider_types', [ProviderApiController::class, 'apiProviderTypes']);
Route::post('providers', [UserApiController::class, 'apiProviders']);
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
    Route::get('delete_address/{id}', [UserApiController::class, 'apiDeleteAddress']);
    Route::post('add_review', [UserApiController::class, 'apiAddReview']);
    Route::post('check_offer', [UserApiController::class, 'apiCheckCoupon']);
    Route::get('user_notification', [UserApiController::class, 'apiUserNotification']);
    Route::get('add_bookmark/{doctor_id}', [UserApiController::class, 'apiAddBookmark']);
    Route::get('faviroute_doctor', [UserApiController::class, 'apiFaviroute']);
    Route::post('/generateAgoraToken', [UserApiController::class, 'apiGenerateToken']);
    Route::get('/video_call_history', [UserApiController::class, 'apiVideoCallHistory']);
    Route::post('/add_call_history', [UserApiController::class, 'apiAddHistory']);
    Route::get('/send_notification', [UserApiController::class, 'apiSendNotification']);
    Route::get('/delete-account', [UserApiController::class, 'deleteAccount']);
    Route::get('/insurers', [UserApiController::class, 'insurers']);
    Route::get('/logout', [UserApiController::class, 'apiLogout']);
    Route::post('/send_message_to_doctor_notification', [UserApiController::class, 'apiSendMessageToDoctorNotificationTrigger'])->middleware('throttle:10,1');
});
// Route::get('/send_notification',[UserApiController::class,'apiSendNotification']);

// ************* DOCTOR / PROVIDER *******************//
Route::get('allMedicines', [DoctorApiController::class, 'apiMedicines']);

Route::middleware('auth:api')->group(function () {
    Route::get('doctor_appointment', [DoctorApiController::class, 'apiDoctorAppointment']);
    Route::post('add_prescription', [DoctorApiController::class, 'apiAddPrescription']);
    Route::get('working_hours', [DoctorApiController::class, 'apiWorkingHours']);
    Route::post('update_time', [DoctorApiController::class, 'apiUpdateWorkingHours']);
    Route::get('doctor_profile', [DoctorApiController::class, 'apiLoginDoctor']);
    Route::post('update_doctor', [DoctorApiController::class, 'apiUpdateDoctor']);
    Route::get('doctor_review', [DoctorApiController::class, 'apiDoctorReview']);
    Route::get('treatment', [DoctorApiController::class, 'apiTreatment']);
    Route::get('categories/{treatment_id}', [DoctorApiController::class, 'apiCategory']);
    Route::get('expertise/{caegory_id}', [DoctorApiController::class, 'apiExpertise']);
    Route::get('hospitals', [DoctorApiController::class, 'apiHospital']);
    Route::post('status_change', [DoctorApiController::class, 'apiStatusChange']);
    Route::post('updatePatientVcall', [DoctorApiController::class, 'apiUpdatePatientVCallSwitch']);
    Route::get('appointment_history', [DoctorApiController::class, 'apiAppointmentHistory']);
    Route::get('payment', [DoctorApiController::class, 'apiPayment']);
    Route::post('payment', [DoctorApiController::class, 'apiPayment']);
    Route::get('subscription', [DoctorApiController::class, 'apiSubscription']);
    Route::get('finance_details', [DoctorApiController::class, 'apiFinanceDetails']);
    Route::get('withdrawal_requests', [DoctorApiController::class, 'apiWithdrawalRequests']);
    Route::post('withdrawal_requests', [DoctorApiController::class, 'apiWithdrawalRequestStore']);
    Route::get('payout_account', [DoctorApiController::class, 'apiPayoutAccountShow']);
    Route::post('payout_account', [DoctorApiController::class, 'apiPayoutAccountUpdate']);
    Route::get('notification', [DoctorApiController::class, 'apiNotification']);
    Route::post('purchase_subscrption', [DoctorApiController::class, 'apiPurchaseSubscription']);
    Route::post('paystack_init', [DoctorApiController::class, 'apiPaystackInit']);
    Route::post('doctor_update_image', [DoctorApiController::class, 'apiUpdateImage']);
    Route::get('cancel_appointment', [DoctorApiController::class, 'apiCancelAppointment']);
    Route::post('doctor_change_password', [DoctorApiController::class, 'apiDoctorChangePassword']);
    Route::post('/generateDoctorAgoraToken',[DoctorApiController::class, 'apiDoctorGenerateToken']);
    Route::post('/send_message_to_patient_notification', [DoctorApiController::class, 'apiSendMessageToPatientNotificationTrigger'])->middleware('throttle:10,1');
});
