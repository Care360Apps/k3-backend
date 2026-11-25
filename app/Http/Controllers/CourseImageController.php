<?php
namespace App\Http\Controllers;

use App\Models\Course;

final class CourseImageController extends Controller
{
    public function show(string $filename)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,199}\.(png|jpe?g|gif|webp)$/iD',$filename),404);
        $published=Course::where('image',$filename)->where('is_published',true)->exists();
        abort_unless($published,404);
        return $this->serve($filename);
    }

    public function preview(int $id)
    {
        abort_unless(\Illuminate\Support\Facades\Gate::allows('course_access') || \Illuminate\Support\Facades\Gate::allows('course_edit'),403);
        $course = Course::withTrashed()->findOrFail($id);
        return $this->serve((string) $course->image);
    }

    private function serve(string $filename)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,199}\.(png|jpe?g|gif|webp)$/iD',$filename),404);
        $directory = storage_path('app/course-images');
        // Legacy files remain readable until administrators replace them.
        if (!str_starts_with($filename, 'trains-') && !file_exists($directory.'/'.$filename)) {
            $directory = public_path('images/upload');
        }
        $root=realpath($directory);
        $path=$directory.'/'.$filename;$resolved=realpath($path);
        abort_unless($root && $resolved && !is_link($path) && str_starts_with($resolved,$root.DIRECTORY_SEPARATOR)
            && is_file($resolved) && is_readable($resolved),404);
        $info=@getimagesize($resolved);$mime=$info['mime'] ?? '';
        abort_unless(in_array($mime,['image/png','image/jpeg','image/gif','image/webp'],true),404);
        return response()->file($resolved,[
            'Content-Type'=>$mime,'X-Content-Type-Options'=>'nosniff',
            'Cache-Control'=>'no-store',
        ]);
    }
}
