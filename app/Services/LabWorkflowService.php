<?php
namespace App\Services;
use App\Models\Report;
use Illuminate\Support\Facades\DB;
class LabWorkflowService {
 public const TRANSITIONS=[
  'booked'=>['sample_collected','processing','cancelled'],
  'sample_collected'=>['processing','cancelled'],
  'processing'=>['result_ready','cancelled'],
  'result_ready'=>['released','processing'],
  'released'=>[], 'cancelled'=>[],
 ];
 public function advance(int $reportId,int $actorId,string $next,?int $labId=null):void {
  DB::transaction(function()use($reportId,$actorId,$next,$labId){
   $q=Report::query()->whereKey($reportId);if($labId!==null)$q->where('lab_id',$labId);
   $report=$q->lockForUpdate()->firstOrFail();$from=$report->workflow_status??'booked';
   abort_unless(in_array($next,self::TRANSITIONS[$from]??[],true),409,'Invalid laboratory workflow transition.');
   abort_if(in_array($next,['result_ready','released'],true)&&!$report->private_result_name,422,'Upload a result before marking it ready.');
   $report->workflow_status=$next;
   if($next==='released')$report->result_released_at=now();
   $report->save();
   DB::table('lab_workflow_events')->insert(['report_id'=>$reportId,'actor_user_id'=>$actorId,'from_status'=>$from,'to_status'=>$next,'created_at'=>now()]);
  });
 }
}
