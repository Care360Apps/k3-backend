<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Services\SafePartnerLink;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BannerController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        abort_if(Gate::denies('banner_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $banners = Banner::orderBy('id', 'DESC')->get();

        return view('superAdmin.banner.banner', compact('banners'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        abort_if(Gate::denies('banner_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        return view('superAdmin.banner.create_banner');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    private function validateCampaign(Request $request, bool $requiredImage): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:90'],
            'description' => ['nullable', 'string', 'max:255'],
            'link' => ['required', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if (! SafePartnerLink::isValidHttps($value)) {
                    $fail('Enter a valid public HTTPS website without account credentials.');
                }
            }],
            'image' => [$requiredImage ? 'required' : 'nullable', 'image', 'mimes:jpeg,png,jpg', 'max:1024'],
            'is_sponsored' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    /** Create an approved promotional placement; no three-banner limit. */
    public function store(Request $request)
    {
        abort_if(Gate::denies('banner_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $data = $this->validateCampaign($request, true);
        $data['image'] = (new CustomController)->imageUpload($request->file('image'));
        $data['status'] = $request->has('status') ? 1 : 0;
        $data['is_sponsored'] = $request->boolean('is_sponsored');
        Banner::create($data);

        return redirect('banner')->withStatus(__('Banner created successfully.'));
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        abort_if(Gate::denies('banner_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $banner = Banner::find($id);

        return view('superAdmin.banner.edit_banner', compact('banner'));

    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        abort_if(Gate::denies('banner_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $data = $this->validateCampaign($request, false);
        if ($request->hasFile('image')) {
            $data['image'] = (new CustomController)->imageUpload($request->file('image'));
        } else {
            unset($data['image']);
        }
        $data['is_sponsored'] = $request->boolean('is_sponsored');
        $data['status'] = $request->has('status') ? 1 : 0;
        Banner::findOrFail($id)->update($data);

        return redirect('banner')->withStatus(__('Banner updated successfully.'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        abort_if(Gate::denies('banner_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $banner = Banner::findOrFail($id);
        (new CustomController)->deleteFile($banner->image);
        $banner->delete();

        return response(['success' => true]);
    }

    public function change_status(Request $reqeust)
    {
        abort_if(Gate::denies('banner_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $banner = Banner::findOrFail($reqeust->id);
        $data['status'] = $banner->status == 1 ? 0 : 1;
        $banner->update($data);

        return response(['success' => true]);
    }
}
