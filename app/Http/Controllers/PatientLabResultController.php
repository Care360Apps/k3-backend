<?php
namespace App\Http\Controllers;
use App\Models\Report;
use App\Services\LabResultFile;
use Illuminate\Http\Request;
class PatientLabResultController extends Controller {
 public function index(Request $request) {
  $user=$request->user('api');abort_unless($user,401);
  $results=Report::query()->where('user_id',$user->id)->orderByDesc('id')->paginate(20,['id','report_id','date','workflow_status','result_released_at']);
  $results->getCollection()->each(fn($report)=>$report->setAppends([]));
  return response()->json(['success'=>true,'data'=>$results]);
 }
 public function download(Request $request,int $reportId) {
  $user=$request->user('api');abort_unless($user,401);
  $report=Report::query()->whereKey($reportId)->where('user_id',$user->id)->where('workflow_status','released')->firstOrFail();
  return LabResultFile::download($report);
 }
}
