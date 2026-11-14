<?php

namespace App\Http\Controllers;

use App\Services\CareerDocumentStorage;
use App\Services\CareerWorkflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CareerDocumentController extends Controller
{
    public function index(Request $r)
    {
        return W::response(DB::table('career_documents')->where('account_id', W::actor($r)->id)
            ->orderByDesc('id')->paginate(20, ['id', 'kind', 'mime', 'bytes', 'review_status', 'created_at']));
    }

    public function upload(Request $r, CareerDocumentStorage $storage)
    {
        $a = W::actor($r);
        $d = $r->validate(['kind' => ['required', Rule::in(['cv', 'education', 'licence', 'identity', 'organization'])],
            'file' => 'required|file|max:5120|mimetypes:application/pdf,image/jpeg,image/png']);
        if ($a->account_type === 'candidate') {
            abort_unless($d['kind'] !== 'organization', 403);
        } else {
            abort_unless(in_array($d['kind'], ['identity', 'organization'], true), 403);
        }
        $saved = $storage->store($r->file('file'));
        try {
            $id = DB::transaction(function () use ($a, $d, $saved) {
                $id = DB::table('career_documents')->insertGetId($saved + ['account_id' => $a->id, 'kind' => $d['kind'],
                    'review_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                W::event('document', $id, 'workforce.'.$a->id, 'uploaded');

                return $id;
            });
        } catch (\Throwable $e) {
            $storage->remove($saved['storage_name']);
            throw $e;
        }

        return W::response(['id' => $id, 'review_status' => 'pending'], 201);
    }

    public function download(Request $r, int $id, CareerDocumentStorage $storage)
    {
        $a = W::actor($r);
        $doc = DB::table('career_documents')->find($id);
        abort_unless($doc, 404);
        if ($doc->kind === 'certificate') {
            abort_unless(DB::table('training_enrollments')->where('account_id', $a->id)->where('certificate_document_id', $id)->where('status', 'completed')->exists(), 404);
        }
        if ((int) $doc->account_id !== $a->id) {
            $org = W::employer($a);
            abort_if($doc->review_status === 'rejected', 404);
            abort_unless($doc->kind === 'cv' && DB::table('career_applications as x')->join('career_jobs as j', 'j.id', '=', 'x.job_id')
                ->where('x.cv_document_id', $id)->where('x.candidate_account_id', $doc->account_id)
                ->whereNotIn('x.status', ['withdrawn', 'rejected'])->where('j.employer_account_id', $org->id)->exists(), 404);
        }
        W::event('document', $id, 'workforce.'.$a->id, 'downloaded');

        return $storage->read($doc);
    }
}
