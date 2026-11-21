<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\FacilityDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FacilityDirectoryAdminController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() && $request->user()->hasRole('super admin'), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'state' => ['nullable', Rule::in(['FCT', 'Nasarawa', 'Plateau'])],
            'status' => ['nullable', Rule::in(['draft', 'published', 'suspended'])],
        ]);
        $query = FacilityDirectory::query();
        if (!empty($validated['state'])) $query->where('state', $validated['state']);
        if (!empty($validated['status'])) $query->where('listing_status', $validated['status']);
        return response()->json($query->orderByDesc('id')->paginate(25))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(['hospital', 'pharmacy'])],
            'name' => ['required', 'string', 'max:200'],
            'state' => ['required', Rule::in(['FCT', 'Nasarawa', 'Plateau'])],
            'lga' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'source_name' => ['required', 'string', 'max:120'],
            'source_id' => ['nullable', 'string', 'max:200'],
            'source_url' => ['nullable', 'url', 'max:2000'],
        ]);
        $data['review_status'] = 'pending';
        $data['listing_status'] = 'draft';
        $data['partner_status'] = 'none';
        return response()->json(['data' => FacilityDirectory::create($data)], 201);
    }

    public function review(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['publish', 'suspend', 'reject'])],
            'evidence_note' => ['required', 'string', 'min:20', 'max:4000'],
            'evidence_confirmed' => ['required', 'accepted'],
        ]);
        $facility = DB::transaction(function () use ($id, $data, $request) {
            $facility = FacilityDirectory::whereKey($id)->lockForUpdate()->firstOrFail();
            $beforeReview = $facility->review_status;
            $beforeListing = $facility->listing_status;
            if ($data['decision'] === 'publish') {
                abort_unless($facility->source_name && $facility->address &&
                    $facility->latitude !== null && $facility->longitude !== null, 422,
                    'Source, address and coordinates required for publication.');
                $facility->review_status = 'verified';
                $facility->listing_status = 'published';
                $facility->last_verified_at = now();
            } elseif ($data['decision'] === 'suspend') {
                $facility->listing_status = 'suspended';
            } else {
                $facility->review_status = 'rejected';
                $facility->listing_status = 'draft';
            }
            $facility->save();
            DB::table('facility_directory_reviews')->insert([
                'facility_directory_id' => $facility->id,
                'reviewer_id' => $request->user()->id,
                'previous_review_status' => $beforeReview,
                'new_review_status' => $facility->review_status,
                'previous_listing_status' => $beforeListing,
                'new_listing_status' => $facility->listing_status,
                'evidence_note' => $data['evidence_note'],
                'created_at' => now(),
            ]);
            return $facility;
        });
        return response()->json(['data' => $facility])->header('Cache-Control', 'no-store');
    }
}
