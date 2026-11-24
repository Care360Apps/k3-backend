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
        $root=realpath(public_path('images/upload'));
        $path=public_path('images/upload/'.$filename);$resolved=realpath($path);
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
