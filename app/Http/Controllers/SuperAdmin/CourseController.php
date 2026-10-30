<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CourseController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('course_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $courses = Course::orderBy('id', 'DESC')->get();

        return view('superAdmin.course.course', compact('courses'));
    }

    public function deleted()
    {
        abort_if(Gate::denies('course_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $courses = Course::onlyTrashed()->orderBy('id', 'DESC')->get();

        return view('superAdmin.course.deleted_course', compact('courses'));
    }

    public function create()
    {
        abort_if(Gate::denies('course_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('superAdmin.course.create_course');
    }

    public function store(Request $request)
    {
        abort_if(Gate::denies('course_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $request->validate([
            'title' => 'bail|required|string|max:255',
            'summary' => 'bail|required|string',
            'details' => 'bail|required|string',
            'image' => 'bail|required|mimes:jpeg,png,jpg|max:1000',
            'duration' => 'bail|required|string|max:120',
            'amount' => 'bail|required|numeric|min:0',
            'link' => 'bail|required|url|max:255',
        ],
            [
                'image.max' => 'The Image May Not Be Greater Than 1 MegaBytes.',
            ]);

        $data = $request->all();
        if ($request->hasFile('image')) {
            $data['image'] = (new CustomController)->imageUpload($request->image);
        }

        Course::create($data);

        return redirect('course')->withStatus(__('Course created successfully..!'));
    }

    public function edit($id)
    {
        abort_if(Gate::denies('course_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $course = Course::findOrFail($id);

        return view('superAdmin.course.edit_course', compact('course'));
    }

    public function update(Request $request, $id)
    {
        abort_if(Gate::denies('course_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $request->validate([
            'title' => 'bail|required|string|max:255',
            'summary' => 'bail|required|string',
            'details' => 'bail|required|string',
            'image' => 'bail|mimes:jpeg,png,jpg|max:1000',
            'duration' => 'bail|required|string|max:120',
            'amount' => 'bail|required|numeric|min:0',
            'link' => 'bail|required|url|max:255',
        ],
            [
                'image.max' => 'The Image May Not Be Greater Than 1 MegaBytes.',
            ]);

        $course = Course::findOrFail($id);
        $data = $request->all();

        if ($request->hasFile('image')) {
            if ($course->image) {
                (new CustomController)->deleteFile($course->image);
            }
            $data['image'] = (new CustomController)->imageUpload($request->image);
        }

        $course->update($data);

        return redirect('course')->withStatus(__('Course updated successfully..!!'));
    }

    public function destroy($id)
    {
        abort_if(Gate::denies('course_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $course = Course::findOrFail($id);
        $course->delete();

        return response(['success' => true]);
    }

    public function restore($id)
    {
        abort_if(Gate::denies('course_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        Course::onlyTrashed()->where('id', $id)->restore();

        return redirect('course/deleted')->withStatus(__('Course restored successfully..!!'));
    }

    public function forceDelete($id)
    {
        abort_if(Gate::denies('course_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $course = Course::onlyTrashed()->findOrFail($id);
        if ($course->image) {
            (new CustomController)->deleteFile($course->image);
        }
        $course->forceDelete();

        return response(['success' => true]);
    }
}
