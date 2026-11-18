<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Offer;
use App\Models\User;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OfferController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        abort_if(Gate::denies('offer_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $offers = Offer::orderBy('id', 'DESC')->get();

        return view('superAdmin.offer.offer', compact('offers'));
    }

    public function create()
    {
        abort_if(Gate::denies('offer_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $doctors = Doctor::whereStatus(1)->get();
        $users = User::doesntHave('roles')->whereStatus(1)->get();

        return view('superAdmin.offer.create_offer', compact('doctors', 'users'));
    }

    public function store(Request $request)
    {
        abort_if(Gate::denies('offer_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $request->validate([
            'name' => 'bail|required',
            'offer_code' => 'bail|required',
            'max_use' => 'bail|required|integer|min:1',
            'min_discount' => 'bail|required|numeric|min:0',
            'user_id' => 'bail|required|array|min:1',
            'user_id.*' => 'required|integer|exists:users,id',
            'doctor_id' => 'bail|required|array|min:1',
            'doctor_id.*' => 'required|integer|exists:doctor,id',
            'desc' => 'bail|required',
            'image' => 'bail|mimes:jpeg,png,jpg|max:1000',
        ],
            [
                'image.max' => 'The Image May Not Be Greater Than 1 MegaBytes.',
            ]);
        $data = $request->only(['name', 'offer_code', 'max_use', 'min_discount', 'user_id', 'doctor_id', 'desc', 'start_end_date', 'update_start_end_date', 'discount_type', 'discount', 'is_flat', 'flatDiscount']);
        if ($request->hasFile('image')) {
            $data['image'] = (new CustomController)->imageUpload($request->image);
        } else {
            $data['image'] = 'defaultUser.png';
        }
        $data['status'] = 1;
        $data['doctor_id'] = implode(',', $data['doctor_id']);
        $data['user_id'] = implode(',', $data['user_id']);
        $data['is_flat'] = $request->has('is_flat') ? 1 : 0;
        $data['flatDiscount'] = $request->flatDiscount != '' ? $request->flatDiscount : null;
        $data['discount'] = $request->discount != '' ? $request->discount : null;
        Offer::create($data);

        return redirect('offer')->withStatus(__('offer created successfully..!!'));
    }

    public function show(Offer $offer)
    {
        //
    }

    public function edit($id)
    {
        abort_if(Gate::denies('offer_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $doctors = Doctor::whereStatus(1)->get();
        $users = User::doesntHave('roles')->whereStatus(1)->get();
        $offer = Offer::findOrFail($id);

        return view('superAdmin.offer.edit_offer', compact('doctors', 'users', 'offer'));
    }

    public function update(Request $request, $id)
    {
        abort_if(Gate::denies('offer_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $request->validate([
            'name' => 'bail|required',
            'offer_code' => 'bail|required',
            'max_use' => 'bail|required|integer|min:1',
            'min_discount' => 'bail|required|numeric|min:0',
            'user_id' => 'bail|required|array|min:1',
            'user_id.*' => 'required|integer|exists:users,id',
            'doctor_id' => 'bail|required|array|min:1',
            'doctor_id.*' => 'required|integer|exists:doctor,id',
            'desc' => 'bail|required',
            'image' => 'bail|mimes:jpeg,png,jpg|max:1000',
        ],
            [
                'image.max' => 'The Image May Not Be Greater Than 1 MegaBytes.',
            ]);
        $offer = Offer::findOrFail($id);
        $data = $request->only(['name', 'offer_code', 'max_use', 'min_discount', 'user_id', 'doctor_id', 'desc', 'start_end_date', 'update_start_end_date', 'discount_type', 'discount', 'is_flat', 'flatDiscount']);
        $data = array_filter($data, function ($a) {
            return $a !== '';
        });
        if ($request->hasFile('image')) {
            (new CustomController)->deleteFile($offer->image);
            $data['image'] = (new CustomController)->imageUpload($request->image);
        }
        $data['start_end_date'] = $data['update_start_end_date'];
        $data['doctor_id'] = implode(',', $data['doctor_id']);
        $data['user_id'] = implode(',', $data['user_id']);
        $data['is_flat'] = $request->has('is_flat') ? 1 : 0;
        $data['flatDiscount'] = $request->flatDiscount != '' ? $request->flatDiscount : null;
        $offer->update($data);

        return redirect('offer')->withStatus(__('offer update successfully..!!'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Offer  $offer
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        abort_if(Gate::denies('offer_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $offer = Offer::findOrFail($id);
        (new CustomController)->deleteFile($offer->image);
        $offer->delete();

        return response(['success' => true]);
    }

    public function change_status(Request $reqeust)
    {
        abort_if(Gate::denies('offer_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $offer = Offer::findOrFail($reqeust->id);
        $data['status'] = $offer->status == 1 ? 0 : 1;
        $offer->update($data);

        return response(['success' => true]);
    }
}
