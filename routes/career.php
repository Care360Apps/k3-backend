<?php

use App\Http\Controllers\CareerDocumentController as D;
use App\Http\Controllers\CareerRecruitmentController as C;
use App\Http\Controllers\TrainingWorkflowController as T;
use App\Http\Middleware\AuthenticateWorkforce;
use App\Http\Middleware\CareerWorkflowsEnabled;
use App\Http\Middleware\WorkforcePilotEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware([\App\Http\Middleware\CareerSafeErrors::class])->group(function(){
Route::get('career/v1/status', fn () => response()->json(['success' => true, 'data' => [
    'workflows_open' => (bool) config('career.enabled', false), 'training_mode' => 'administrator_verified',
]]))->middleware('throttle:15,1,career-status');
Route::get('training/v1/courses', [T::class, 'courses'])->middleware([CareerWorkflowsEnabled::class, 'throttle:30,1,training-catalogue']);
Route::middleware([WorkforcePilotEnabled::class, CareerWorkflowsEnabled::class, AuthenticateWorkforce::class, \App\Http\Middleware\CareerNoticeConsent::class, 'throttle:40,1,career-workflows'])->group(function () {
    $recruiters = \App\Http\Controllers\CareerRecruiterController::class;
    Route::get('workforce/v1/recruiters', [$recruiters, 'index']);
    Route::post('workforce/v1/recruiters', [$recruiters, 'invite']);
    Route::post('workforce/v1/recruiters/{id}/response', [$recruiters, 'respond'])->whereNumber('id');
    $invitations = \App\Http\Controllers\CareerInvitationController::class;
    Route::get('workforce/v1/invitations', [$invitations, 'index']);
    Route::post('workforce/v1/invitations', [$invitations, 'invite']);
    Route::post('workforce/v1/invitations/{id}/response', [$invitations, 'respond'])->whereNumber('id');
    Route::get('workforce/v1/invitations/{id}/messages', [$invitations, 'messages'])->whereNumber('id');
    Route::post('workforce/v1/invitations/{id}/messages', [$invitations, 'sendMessage'])->whereNumber('id');
    Route::post('workforce/v1/invitations/{id}/report', [$invitations, 'report'])->whereNumber('id');
    Route::get('workforce/v1/portfolio', [C::class, 'portfolio']);
    Route::post('workforce/v1/portfolio', [C::class, 'savePortfolio']);
    Route::get('workforce/v1/documents', [D::class, 'index']);
    Route::post('workforce/v1/documents', [D::class, 'upload'])->middleware('throttle:5,1,career-uploads');
    Route::get('workforce/v1/documents/{id}', [D::class, 'download'])->whereNumber('id');
    Route::get('workforce/v1/jobs', [C::class, 'jobs']);
    Route::post('workforce/v1/jobs', [C::class, 'saveJob']);
    Route::post('workforce/v1/jobs/{id}/close', [C::class, 'closeJob'])->whereNumber('id');
    Route::post('workforce/v1/jobs/{id}/apply', [C::class, 'apply'])->whereNumber('id');
    Route::get('workforce/v1/applications', [C::class, 'applications']);
    Route::post('workforce/v1/applications/{id}/status', [C::class, 'transition'])->whereNumber('id');
    Route::post('workforce/v1/applications/{id}/offer-response', [C::class, 'offerResponse'])->whereNumber('id');
    Route::post('workforce/v1/applications/{id}/interviews', [C::class, 'interview'])->whereNumber('id');
    Route::post('workforce/v1/interviews/{id}/response', [C::class, 'interviewResponse'])->whereNumber('id');
    Route::post('workforce/v1/notifications/{id}/read', [C::class, 'readNotification'])->whereNumber('id');
    Route::get('workforce/v1/notifications', [C::class, 'notifications']);
    Route::get('workforce/v1/candidates/{candidateId}/training', [T::class, 'credentials'])->whereNumber('candidateId');
    Route::post('training/v1/courses/{id}/enroll', [T::class, 'enroll'])->whereNumber('id');
    Route::get('training/v1/enrollments', [T::class, 'enrollments']);
    Route::post('training/v1/enrollments/{id}/cancel', [T::class, 'cancelEnrollment'])->whereNumber('id');
    Route::get('training/v1/requests', [T::class, 'requests']);
    Route::post('training/v1/requests', [T::class, 'requestTraining']);
    Route::post('training/v1/requests/{id}/response',[T::class, 'requestResponse'])->whereNumber('id');
});

// Access/export/deletion requests remain available without accepting a changed
// notice or enabling recruitment. Each route still requires its own identity.
Route::prefix('workforce/v1/privacy')->middleware([AuthenticateWorkforce::class,'throttle:10,1,career-privacy'])->group(function(){
    $c=\App\Http\Controllers\CareerPrivacyController::class;
    Route::get('/',[$c,'index']); Route::get('export',[$c,'export']);
    Route::post('/',[$c,'requestClosure']);
    Route::get('documents/{id}',[$c,'document'])->whereNumber('id');
});

Route::post('workforce/v1/password-reset/request',[\App\Http\Controllers\CareerPasswordController::class,'request'])->middleware('throttle:career-recovery');
Route::post('workforce/v1/password-reset/complete',[\App\Http\Controllers\CareerPasswordController::class,'complete'])->middleware('throttle:10,1,career-reset-complete');

});
