<?php

namespace App\Http\Controllers;

use App\Models\FacilityDirectory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class FacilityDirectoryController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'state' => ['nullable', Rule::in(['FCT', 'Nasarawa', 'Plateau'])],
            'type' => ['nullable', Rule::in(['hospital', 'pharmacy'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = FacilityDirectory::query()->published();
        if (!empty($validated['state'])) $query->where('state', $validated['state']);
        if (!empty($validated['type'])) $query->where('type', $validated['type']);
        if (!empty($validated['search'])) {
            $search = addcslashes($validated['search'], '%_\\');
            $query->where('name', 'like', '%'.$search.'%');
        }
        return response()->json($query->select([
            'id', 'type', 'name', 'state', 'lga', 'address',
            'latitude', 'longitude', 'phone', 'last_verified_at'
        ])->orderBy('name')->orderBy('id')->paginate($validated['per_page'] ?? 20))
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function show($id)
    {
        $facility = FacilityDirectory::query()->published()->select([
            'id', 'type', 'name', 'state', 'lga', 'address',
            'latitude', 'longitude', 'phone', 'last_verified_at'
        ])->findOrFail($id);
        return response()->json(['data' => $facility])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
