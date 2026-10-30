<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ProviderType;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProviderTypeController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('provider_type_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $providerTypes = ProviderType::orderBy('id', 'DESC')->get();

        return view('superAdmin.providerType.provider_type', compact('providerTypes'));
    }

    public function create()
    {
        abort_if(Gate::denies('provider_type_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('superAdmin.providerType.create_provider_type');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|unique:provider_types,name',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
        ]);
        $data = $request->all();
        $data['status'] = $request->has('status') ? 1 : 0;
        ProviderType::create($data);

        return redirect('provider_type')->withStatus(__('Provider type created successfully.'));
    }

    public function edit($id)
    {
        abort_if(Gate::denies('provider_type_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $providerType = ProviderType::findOrFail($id);

        return view('superAdmin.providerType.edit_provider_type', compact('providerType'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|unique:provider_types,name,'.$id.',id',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
        ]);
        $providerType = ProviderType::findOrFail($id);
        $data = $request->all();
        $data['status'] = $request->has('status') ? 1 : 0;
        $providerType->update($data);

        return redirect('provider_type')->withStatus(__('Provider type updated successfully.'));
    }

    public function destroy($id)
    {
        abort_if(Gate::denies('provider_type_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $providerType = ProviderType::findOrFail($id);
        if ($providerType->doctors()->exists()) {
            return response(['success' => false, 'msg' => 'Cannot delete: providers exist for this type.']);
        }
        $providerType->delete();

        return response(['success' => true]);
    }

    public function change_status(Request $request)
    {
        abort_if(Gate::denies('provider_type_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $providerType = ProviderType::findOrFail($request->id);
        $providerType->update(['status' => $providerType->status == 1 ? 0 : 1]);

        return response(['success' => true]);
    }
}
