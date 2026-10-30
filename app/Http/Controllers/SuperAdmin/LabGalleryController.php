<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SuperAdmin\CustomController;
use App\Models\Lab;
use App\Models\LabGallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class LabGalleryController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_if(Gate::denies('lab_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

            return $next($request);
        });
    }

    public function index($lab_id)
    {
        $lab = Lab::find($lab_id);
        $galleries = LabGallery::where('lab_id', $lab_id)->get();

        return view('superAdmin.lab.lab_gallery', compact('lab', 'galleries'));
    }

    public function store(Request $request)
    {
        $data = $request->all();
        $data['lab_id'] = $request->lab_id;
        $file = $request->file('file');
        if ($request->hasFile('file')) {
            $data['image'] = (new CustomController)->imageUpload($request->file);
        }
        LabGallery::create($data);

        return response()->json(['success' => $file->getClientOriginalName()]);
    }

    public function destroy($id)
    {
        $gallery = LabGallery::find($id);
        (new CustomController)->deleteFile($gallery->image);
        $gallery->delete();

        return response(['success' => true]);
    }
}
