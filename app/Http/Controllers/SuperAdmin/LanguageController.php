<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LanguageController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        abort_if(Gate::denies('language_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $languages = Language::orderBy('id', 'DESC')->get();

        return view('superAdmin.language.language', compact('languages'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        abort_if(Gate::denies('language_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('superAdmin.language.create_language');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['bail', 'required', 'regex:'.self::NAME_PATTERN, 'unique:language,name'],
            'direction' => 'required',
            'json_file' => 'bail|required|file|max:2048',
            'image' => 'bail|required|mimes:jpeg,png,jpg|max:1000',
        ],
            [
                'image.max' => 'The Image May Not Be Greater Than 1 MegaBytes.',
            ]);
        $data = $request->only(['name', 'direction']);
        $data['image'] = $request->hasFile('image')
            ? (new CustomController)->safeUpload($request->file('image'), public_path('/images/upload'), CustomController::IMAGE_EXTENSIONS)
            : 'defaultUser.png';
        $data['json_file'] = $this->storeTranslations($request->file('json_file'), $data['name']);
        $data['status'] = 1;
        Language::create($data);

        return redirect('/language');
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
        abort_if(Gate::denies('language_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $language = Language::find($id);

        return view('superAdmin.language.edit_language', compact('language'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        abort_if(Gate::denies('language_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $language = Language::findOrFail($id);
        $request->validate([
            'name' => ['bail', 'nullable', 'regex:'.self::NAME_PATTERN, 'unique:language,name,'.$language->id],
            'direction' => 'required',
            'json_file' => 'bail|nullable|file|max:2048',
            'image' => 'bail|mimes:jpeg,png,jpg|max:1000',
        ],
            [
                'image.max' => 'The Image May Not Be Greater Than 1 MegaBytes.',
            ]);
        $data = $request->only(['direction']);
        if ($request->filled('name') && $language->name !== 'English') {
            $data['name'] = $request->name;
        }
        if ($request->hasFile('image')) {
            $data['image'] = (new CustomController)->safeUpload($request->file('image'), public_path('/images/upload'), CustomController::IMAGE_EXTENSIONS);
        }
        if ($request->hasFile('json_file')) {
            $data['json_file'] = $this->storeTranslations($request->file('json_file'), $data['name'] ?? $language->name, $language->json_file);
        }
        $language->update($data);

        return redirect('/language');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        abort_if(Gate::denies('language_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $language = Language::findOrFail($id);
        if ($language['name'] == 'English') {
            return response(['success' => false, 'data' => 'This data can not be delete.']);
        }
        $this->deleteTranslations($language->json_file);

        if ($language->image != 'prod_default.png' && $language->image != 'defaultUser.png') {
            if (File::exists(public_path('images/upload/'.basename($language->image)))) {
                File::delete(public_path('images/upload/'.basename($language->image)));
            }
        }
        $language->delete();

        return response(['success' => true]);
    }

    public function change_status(Request $reqeust)
    {
        abort_if(Gate::denies('language_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $language = Language::findOrFail($reqeust->id);
        $data['status'] = $language->status == 1 ? 0 : 1;
        $language->update($data);

        return response(['success' => true]);
    }

    public function downloadFile()
    {
        $pathToFile = resource_path().'/lang/English.json';
        $name = 'English.json';
        $headers = ['Content-Type: application/json'];

        return response()->download($pathToFile, $name, $headers);
    }

    /** Language names become translation file names (resources/lang/{name}.json), so no dots or slashes. */
    private const NAME_PATTERN = '/^\\pL[\\pL _-]{0,49}$/u';

    private function storeTranslations($file, string $name, ?string $replaces = null): string
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw ValidationException::withMessages(['name' => __('Invalid language name.')]);
        }
        $json = json_decode((string) file_get_contents($file->getRealPath()), true);
        if (! is_array($json)) {
            throw ValidationException::withMessages(['json_file' => __('The translation file must be valid JSON.')]);
        }
        if ($replaces) {
            $this->deleteTranslations($replaces);
        }
        File::put(resource_path('lang').DIRECTORY_SEPARATOR.$name.'.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $name.'.json';
    }

    private function deleteTranslations(?string $fileName): void
    {
        $path = resource_path('lang').DIRECTORY_SEPARATOR.basename((string) $fileName);
        if ($fileName && str_ends_with($path, '.json') && File::exists($path)) {
            File::delete($path);
        }
    }
}
