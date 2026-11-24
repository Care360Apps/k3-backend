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

    private function courseData(Request $request, bool $new): array
    {
        if (trim((string) $request->input('amount', '')) === '') {
            $request->merge(['amount' => null]);
        }
        $data = $request->validate([
            'title' => ['required','string','max:255'],
            'provider' => ['required','in:cnu,speakout'],
            'summary' => ['required','string','max:2000'],
            'details' => ['required','string','max:30000'],
            'duration' => ['required','string','max:120'],
            'amount' => ['nullable','numeric','min:0','max:99999999.99','decimal:0,2'],
            'image' => [$new ? 'required' : 'nullable','image','mimes:jpeg,png,jpg','max:2048'],
            'is_published' => ['required','boolean'],
            'link' => ['required','string','max:255', function($attribute,$value,$fail) use ($request) {
                if (! \App\Services\SafePartnerLink::isValidHttps($value)) {
                    $fail('Enter a public HTTPS application link.');
                }
                $host = strtolower((string) parse_url($value, PHP_URL_HOST));
                if ($request->input('provider') === 'speakout'
                    && $host !== 'speakoutmentalhealth.org' && ! str_ends_with($host,'.speakoutmentalhealth.org')) {
                    $fail('SpeakOut registration must use the existing SpeakOut website.');
                }
            }],
        ]);
        if ($data['provider'] === 'speakout') {
            $data['amount'] = 0;
        } elseif ($data['amount'] !== null && (float) $data['amount'] <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount'=>'CNU tuition must be greater than zero, or blank for Contact for tuition.']);
        }
        foreach (['title','summary','details','duration'] as $field) {
            $data[$field] = trim(strip_tags($data[$field]));
            if ($data[$field] === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([$field=>'Enter visible text.']);
            }
        }
        return $data;
    }

    public function store(Request $request)
    {
        abort_if(Gate::denies('course_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $data = $this->courseData($request, true);
        $data['image'] = (new CustomController)->imageUpload($request->file('image'));

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
        $course = Course::findOrFail($id);
        $data = $this->courseData($request, false);
        if ($request->hasFile('image')) {
            // Store first so a failed upload cannot destroy the existing image.
            $data['image'] = (new CustomController)->imageUpload($request->file('image'));
        } else {
            unset($data['image']);
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
