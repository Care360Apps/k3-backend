<?php
namespace App\Http\Controllers;
use App\Models\{WorkforceCandidate,WorkforceEmployer};
use App\Services\WorkforceReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
class WorkforceReviewController extends Controller
{
 public function index(Request $request) {
  abort_unless(Gate::allows('workforce_review'),403);
  $kind=$request->query('kind','candidate');abort_unless(in_array($kind,['candidate','employer'],true),404);
  $r=$request->validate(['account_id'=>'nullable|integer|min:1']);
  $query=($kind==='candidate'?WorkforceCandidate::query():WorkforceEmployer::query())->with('account');
  if(!empty($r['account_id']))$query->where('account_id',$r['account_id']);
  $profiles=$query->orderByDesc('id')->paginate(20)->withQueryString();
  if(\Illuminate\Support\Facades\Schema::hasTable('career_portfolios'))foreach($profiles as $p){
   $portfolio=\Illuminate\Support\Facades\DB::table('career_portfolios')->where('account_id',$p->account_id)->first();
   $p->setAttribute('career_portfolio',$portfolio?\App\Services\CareerWorkflow::unpack($portfolio->payload):[]);
  }
  return view('superAdmin.workforce.review',compact('kind','profiles'));
 }
 public function store(Request $request, WorkforceReviewService $service) {
  abort_unless(Gate::allows('workforce_review'),403);
  abort_unless(config('workforce.enabled',false),503);
  $data=$request->validate([
   'kind'=>['required',Rule::in(['candidate','employer'])], 'profile_id'=>'required|integer|min:1',
   'decision'=>['required',Rule::in(['verified','rejected','revoked'])],
   'evidence_sha256'=>'required|regex:/^[a-f0-9]{64}$/',
   'email_checked'=>'nullable|boolean','licence_evidence_sha256'=>'nullable|regex:/^[a-f0-9]{64}$/',
   'licence_expires'=>'nullable|date_format:Y-m-d|after_or_equal:today',
  ]);
  try {
   $service->review($data['kind'],(int)$data['profile_id'],$data['decision'],'admin.'.auth()->id(),$data['evidence_sha256'],$request->boolean('email_checked'),$data['licence_evidence_sha256']??'',$data['licence_expires']??'');
  } catch (\DomainException $e) {
   return back()->withErrors(['review'=>$e->getMessage()]);
  }
  return back()->withStatus('Verification decision recorded.');
 }
}
