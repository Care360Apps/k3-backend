<?php
namespace App\Http\Controllers;
use App\Models\{Appointment,Doctor,Lab,Report,ClinicalObservation,ClinicalOrder,ClinicalCriticalResult,ClinicalWorkflowEvent};
use App\Services\LabResultFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate,Crypt};
class ClinicalWorkflowController extends Controller {
 private function doctor(Request $r):Doctor {
  $u=$r->user();abort_unless($u&&$u->hasRole('doctor'),403);
  return Doctor::where('user_id',$u->id)->where('is_approved',1)->where('status',1)->where(fn($q)=>$q->where('user_role','doctor')->orWhereNull('user_role'))->firstOrFail();
 }
 private function appointment(Request $r,int $id):array {
  $doctor=$this->doctor($r);$a=Appointment::where('doctor_id',$doctor->id)->findOrFail($id);return [$doctor,$a];
 }
 private function labScope():?int {
  abort_unless(Gate::allows('test_report'),403);
  if(auth()->user()->hasRole('laboratory')){$id=Lab::where('user_id',auth()->id())->where('status',1)->value('id');abort_unless($id,403);return (int)$id;}return null;
 }
 private function json(array $data,int $status=200){return response()->json(['success'=>true,'data'=>$data],$status)->header('Cache-Control','private, no-store');}
 private function event(int $orderId,int $actor,string $action,?string $details=null):void {
  DB::table('clinical_workflow_events')->insert(['order_id'=>$orderId,'actor_user_id'=>$actor,'action'=>$action,'details'=>$details===null?null:Crypt::encryptString($details),'created_at'=>now()]);
 }
 public function encounters(Request $r){
  $d=$this->doctor($r);$v=$r->validate(['page'=>'sometimes|integer|min:1|max:1000000','q'=>'sometimes|nullable|string|max:120']);
  $q=Appointment::where('doctor_id',$d->id);if(!empty($v['q']))$q->where('patient_name','like','%'.$v['q'].'%');
  $rows=$q->orderByDesc('id')->paginate(20,['id','appointment_id','patient_name','date','time','appointment_status']);
  return $this->json(['encounters'=>$rows->getCollection()->map(fn($a)=>$a->only(['id','appointment_id','patient_name','date','time','appointment_status']))->all(),'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
 }
 public function workspace(Request $r,int $id){
  [$d,$a]=$this->appointment($r,$id);
  $v=$r->validate(['page'=>'sometimes|integer|min:1|max:1000000']);$page=$v['page']??1;
  $orders=ClinicalOrder::where('doctor_id',$d->id)->where('appointment_id',$a->id)->orderByDesc('id')->paginate(20,['*'],'page',$page);
  $observations=ClinicalObservation::where('doctor_id',$d->id)->where('appointment_id',$a->id)->orderByDesc('id')->paginate(20,['*'],'page',$page);
  return $this->json(['appointment_id'=>$a->id,'observations'=>$observations->items(),'orders'=>$orders->items(),'critical_results'=>ClinicalCriticalResult::whereIn('order_id',$orders->pluck('id'))->orderByDesc('id')->get()->all(),'labs'=>Lab::where('status',1)->orderBy('name')->limit(500)->get(['id','name'])->all(),'meta'=>['page'=>(int)$page,'last_page'=>max($orders->lastPage(),$observations->lastPage())]]);

 }
 public function observe(Request $r,int $id){
  [$d,$a]=$this->appointment($r,$id);
  $rules=['allergy_status'=>'required|in:unknown,none_known,reported','allergies'=>'present|array|max:30','allergies.*'=>'required|array:substance,reaction,severity','allergies.*.substance'=>'required|string|max:160','allergies.*.reaction'=>'required|string|max:500','allergies.*.severity'=>'required|in:unknown,mild,moderate,severe','source'=>'required|in:measured,patient_reported','observed_at'=>['required','date','before_or_equal:now','regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],'vitals'=>'present|array:systolic_mmHg,diastolic_mmHg,pulse_bpm,respiratory_rate_per_min,temperature_c,spo2_percent,weight_kg,height_cm'];
  foreach(['systolic_mmHg','diastolic_mmHg','pulse_bpm','respiratory_rate_per_min','temperature_c','weight_kg','height_cm'] as $field)$rules['vitals.'.$field]='sometimes|numeric|between:0,9999';
  $rules['vitals.spo2_percent']='sometimes|numeric|between:0,100';
  $v=$r->validate($rules);
  abort_if(($v['allergy_status']==='reported'&&count($v['allergies'])===0)||($v['allergy_status']!=='reported'&&count($v['allergies'])>0),422,'Allergy status must agree with the recorded entries.');
  $row=ClinicalObservation::create(['appointment_id'=>$a->id,'doctor_id'=>$d->id,'user_id'=>$a->user_id,'actor_user_id'=>$r->user()->id,'payload'=>['allergy_status'=>$v['allergy_status'],'allergies'=>$v['allergies'],'vitals'=>$v['vitals'],'source'=>$v['source']],'observed_at'=>\Carbon\Carbon::parse($v['observed_at'])->utc()]);
  return $this->json(['observation'=>$row],201);
 }
 public function order(Request $r,int $id){
  [$d,$a]=$this->appointment($r,$id);
  $v=$r->validate(['client_request_id'=>'required|uuid','kind'=>'required|in:investigation,referral','title'=>'required|string|max:200','details'=>'required|string|max:4000','clinical_contact'=>'required|string|max:240','urgency'=>'required|in:routine,urgent,emergency','target'=>'required_if:kind,referral|nullable|string|max:240','lab_id'=>'required_if:kind,investigation|nullable|integer|min:1']);
  if($v['kind']==='investigation')abort_unless(Lab::whereKey($v['lab_id'])->where('status',1)->exists(),422,'Choose an active laboratory.');
  $fingerprint=hash('sha256',json_encode([$a->id,$v['kind'],trim($v['title']),trim($v['details']),trim($v['clinical_contact']),$v['urgency'],$v['target']??null,isset($v['lab_id'])?(int)$v['lab_id']:null],JSON_THROW_ON_ERROR));
  $row=DB::transaction(function()use($r,$d,$a,$v,$fingerprint){
   $existing=ClinicalOrder::where('doctor_id',$d->id)->where('client_request_id',$v['client_request_id'])->lockForUpdate()->first();
   if($existing){abort_unless(hash_equals($existing->request_fingerprint,$fingerprint),409,'Request key already used for different content.');return $existing;}
   $o=ClinicalOrder::create(['client_request_id'=>$v['client_request_id'],'request_fingerprint'=>$fingerprint,'appointment_id'=>$a->id,'doctor_id'=>$d->id,'user_id'=>$a->user_id,'kind'=>$v['kind'],'urgency'=>$v['urgency'],'lab_id'=>$v['kind']==='investigation'?$v['lab_id']:null,'payload'=>['title'=>trim($v['title']),'details'=>trim($v['details']),'clinical_contact'=>trim($v['clinical_contact']),'target'=>$v['target']??null,'patient_name'=>$a->patient_name,'patient_age'=>$a->age]]);$this->event($o->id,$r->user()->id,'requested');return $o;},3);
  return $this->json(['order'=>$row],$row->wasRecentlyCreated?201:200);
 }
 public function transition(Request $r,int $id){
  $d=$this->doctor($r);$v=$r->validate(['status'=>'required|in:cancelled,completed,reviewed','note'=>'required|string|max:2000']);
  DB::transaction(function()use($r,$d,$v,$id){$o=ClinicalOrder::where('doctor_id',$d->id)->whereKey($id)->lockForUpdate()->firstOrFail();
   $report=$o->report_id?Report::find($o->report_id):null;
   $allowed=($v['status']==='cancelled'&&$o->status==='requested'&&!$o->report_id)||($v['status']==='completed'&&$o->kind==='referral'&&$o->status==='requested')||($v['status']==='reviewed'&&$o->kind==='investigation'&&$o->status==='requested'&&$report?->workflow_status==='released');
   abort_unless($allowed,409,'This request cannot make that transition.');
   if($v['status']==='reviewed')abort_if(ClinicalCriticalResult::where('order_id',$o->id)->whereNull('acknowledged_at')->exists(),409,'Acknowledge the critical result first.');
   $o->status=$v['status'];$o->save();$this->event($o->id,$r->user()->id,$v['status'],$v['note']);
  });return $this->json(['saved'=>true]);
 }
 public function result(Request $r,int $id){
  $d=$this->doctor($r);$o=ClinicalOrder::where('doctor_id',$d->id)->whereKey($id)->firstOrFail();
  $report=Report::whereKey($o->report_id)->where('user_id',$o->user_id)->where('lab_id',$o->lab_id)->where('workflow_status','released')->firstOrFail();
  $response=LabResultFile::download($report);$this->event($o->id,$r->user()->id,'result_accessed');return $response;
 }
 public function criticalInbox(Request $r){
  $d=$this->doctor($r);$orders=ClinicalOrder::where('doctor_id',$d->id)->select('id');
  $rows=ClinicalCriticalResult::whereIn('order_id',$orders)->whereNull('acknowledged_at')->orderBy('id')->paginate(25);
  $safe=$rows->getCollection()->map(function($flag){$order=ClinicalOrder::findOrFail($flag->order_id);return array_merge($flag->toArray(),['appointment_id'=>$order->appointment_id,'patient_name'=>$order->payload['patient_name']??null,'request_title'=>$order->payload['title']??null,'contact_updates'=>ClinicalWorkflowEvent::where('order_id',$order->id)->where('action','lab_contact_attempt')->orderByDesc('id')->limit(10)->get(['actor_user_id','details','created_at'])->all()]);})->all();
  return $this->json(['critical_results'=>$safe,'meta'=>['current_page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total()]]);
 }
 public function acknowledge(Request $r,int $id){
  $d=$this->doctor($r);$v=$r->validate(['reviewed_result'=>'accepted','note'=>'required|string|max:2000']);
  DB::transaction(function()use($r,$d,$v,$id){$flag=ClinicalCriticalResult::whereKey($id)->lockForUpdate()->firstOrFail();$o=ClinicalOrder::whereKey($flag->order_id)->where('doctor_id',$d->id)->firstOrFail();
   abort_if($flag->acknowledged_at!==null,409,'This acknowledgement is already recorded.');
   abort_unless(Report::whereKey($flag->report_id)->where('user_id',$o->user_id)->where('lab_id',$o->lab_id)->where('workflow_status','released')->exists(),409);
   $flag->acknowledged_by=$r->user()->id;$flag->acknowledged_at=now();$flag->acknowledgement=$v['note'];$flag->save();$this->event($o->id,$r->user()->id,'critical_acknowledged',$v['note']);
  });return $this->json(['acknowledged'=>true]);
 }
 public function labIndex(){
  $lab=$this->labScope();$q=ClinicalOrder::where('kind','investigation');if($lab!==null)$q->where('lab_id',$lab);
  $orders=$q->orderByDesc('id')->paginate(30);$flags=ClinicalCriticalResult::whereIn('order_id',$orders->pluck('id'))->get()->keyBy('order_id');
  $reports=Report::whereIn('id',$orders->pluck('report_id')->filter())->get()->keyBy('id');
  $events=ClinicalWorkflowEvent::whereIn('order_id',$orders->pluck('id'))->orderByDesc('id')->get()->groupBy('order_id');
  return response()->view('superAdmin.lab.clinical_requests',compact('orders','flags','events','reports'))->header('Cache-Control','private, no-store');
 }
 public function link(Request $r){
  $lab=$this->labScope();$v=$r->validate(['order_id'=>'required|integer|min:1','report_id'=>'required|integer|min:1','identity_checked'=>'accepted']);
  DB::transaction(function()use($r,$lab,$v){$q=ClinicalOrder::whereKey($v['order_id'])->where('kind','investigation');if($lab!==null)$q->where('lab_id',$lab);$o=$q->lockForUpdate()->firstOrFail();abort_unless($o->status==='requested'&&$o->report_id===null,409);
   $report=Report::whereKey($v['report_id'])->where('lab_id',$o->lab_id)->where('user_id',$o->user_id)->lockForUpdate()->firstOrFail();
   $normal=fn($s)=>mb_strtolower(preg_replace('/\\s+/u',' ',trim((string)$s)));
   abort_unless(!empty($o->payload['patient_name'])&&$normal($o->payload['patient_name'])===$normal($report->patient_name)&&isset($o->payload['patient_age'])&&(string)$o->payload['patient_age']===(string)$report->age,422,'Patient name and age must match the request and booking. Verify identity before linking.');
   abort_if($report->workflow_status==='cancelled',409);abort_if(ClinicalOrder::where('report_id',$report->id)->exists(),409,'Booking already linked.');
   $o->report_id=$report->id;$o->save();$this->event($o->id,$r->user()->id,'booking_linked','Patient identity checked against request and booking.');
  });return back()->withStatus('Existing test booking linked. Booking charges and payment status are unchanged.');
 }
 public function flag(Request $r){
  $lab=$this->labScope();$v=$r->validate(['order_id'=>'required|integer|min:1','reason'=>'required|string|max:2000','contact_attempt'=>'required|string|max:2000']);
  DB::transaction(function()use($r,$lab,$v){$q=ClinicalOrder::whereKey($v['order_id'])->where('kind','investigation');if($lab!==null)$q->where('lab_id',$lab);$o=$q->lockForUpdate()->firstOrFail();
   abort_unless($o->report_id&&$o->status!=='cancelled',409);
   $report=Report::whereKey($o->report_id)->where('lab_id',$o->lab_id)->where('user_id',$o->user_id)->lockForUpdate()->firstOrFail();abort_unless($report->workflow_status==='released',409,'Release the reviewed result before flagging it here. Follow urgent contact procedures without waiting for this app.');
   abort_if(ClinicalCriticalResult::where('order_id',$o->id)->exists(),409,'A critical flag already exists; it cannot be overwritten.');
   LabResultFile::download($report); // A released private document must actually be available for review.
   ClinicalCriticalResult::create(['order_id'=>$o->id,'report_id'=>$report->id,'flagged_by'=>$r->user()->id,'reason'=>$v['reason'],'contact_attempt'=>$v['contact_attempt']]);$this->event($o->id,$r->user()->id,'critical_flagged');
  });return back()->withStatus('Critical result recorded. Continue your approved direct-contact escalation until the clinician acknowledges. App delivery is not guaranteed.');
 }

 public function contact(Request $r){
  $lab=$this->labScope();$v=$r->validate(['order_id'=>'required|integer|min:1','note'=>'required|string|max:2000']);
  $q=ClinicalOrder::whereKey($v['order_id'])->where('kind','investigation');if($lab!==null)$q->where('lab_id',$lab);$o=$q->firstOrFail();
  abort_unless(ClinicalCriticalResult::where('order_id',$o->id)->exists(),409,'Record a critical flag first.');
  $this->event($o->id,$r->user()->id,'lab_contact_attempt',$v['note']);
  return back()->withStatus('Additional contact / correction recorded without changing the original flag or acknowledgement.');
 }
}
