<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class NotificationTemplateController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('superadmin_setting'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $templates = NotificationTemplate::get();
        $notification = NotificationTemplate::first();

        return view('superAdmin.notification_template.notification_template', compact('templates', 'notification'));
    }

    public function edit_notification($id)
    {
        abort_if(Gate::denies('superadmin_setting'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $notification = NotificationTemplate::find($id);

        return response(['success' => true, 'data' => $notification]);
    }

    public function update_template(Request $request, $id)
    {
        abort_if(Gate::denies('superadmin_setting'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $id = NotificationTemplate::find($id);
        $id->update($request->all());

        return redirect()->back()->withStatus(__('Notification Template update successfully..!!'));
    }
}
