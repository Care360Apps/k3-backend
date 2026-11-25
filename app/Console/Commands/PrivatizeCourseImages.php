<?php

namespace App\Console\Commands;

use App\Models\Course;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PrivatizeCourseImages extends Command
{
    protected $signature = 'trains:privatize-images {--apply : Copy legacy images and update course references}';
    protected $description = 'Audit or copy legacy course images into private storage without deleting shared public files';

    public function handle(): int
    {
        $failures = 0;
        $root = realpath(public_path('images/upload'));
        foreach (Course::withTrashed()->whereNotNull('image')->cursor() as $course) {
            $name = $course->image;
            if (!$name || str_starts_with($name, 'trains-')) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,199}\.(png|jpe?g|gif|webp)$/iD', $name)) {
                $this->warn("Invalid legacy image for course {$course->id}");
                $failures++;
                continue;
            }
            $source = public_path('images/upload/'.$name);
            $resolved = realpath($source);
            if (!$root || !$resolved || is_link($source) || !str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || !is_file($resolved)) {
                $this->warn("Missing or unsafe legacy image for course {$course->id}");
                $failures++;
                continue;
            }
            $mime = (@getimagesize($resolved))['mime'] ?? '';
            $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (!isset($extensions[$mime])) {
                $this->warn("Invalid image content for course {$course->id}");
                $failures++;
                continue;
            }
            $this->line("Legacy course {$course->id}: {$name}");
            if (!$this->option('apply')) {
                continue;
            }
            $directory = storage_path('app/course-images');
            File::ensureDirectoryExists($directory);
            $new = 'trains-'.Str::uuid().'.'.$extensions[$mime];
            if (!File::copy($resolved, $directory.'/'.$new)) {
                $failures++;
                continue;
            }
            $changed = Course::withTrashed()->whereKey($course->id)->where('image',$name)->update(['image'=>$new]);
            if (!$changed) {
                File::delete($directory.'/'.$new);
                $failures++;
            }
        }
        $this->warn('Original public files are retained. Verify shared references and remove or deny direct access to legacy files before release.');
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
