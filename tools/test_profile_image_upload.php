<?php

namespace Illuminate\Validation {
    class ValidationException extends \RuntimeException
    {
        public static function withMessages(array $messages): self { return new self(json_encode($messages)); }
    }
}
namespace Illuminate\Http {
    class UploadedFile
    {
        public function __construct(private string $path, private bool $valid = true) {}
        public function isValid(): bool { return $this->valid; }
        public function getSize(): int { return filesize($this->path); }
        public function getRealPath(): string { return $this->path; }
    }
}
namespace {
    require __DIR__.'/../app/Services/ProfileImageUpload.php';
    use App\Services\ProfileImageUpload;
    use Illuminate\Validation\ValidationException;
    use Illuminate\Http\UploadedFile;

    $directory = sys_get_temp_dir().'/care360-avatar-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $service = new ProfileImageUpload();
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGNkYPjPwMDAxMDAwMDAAAALHwEDmIWXfgAAAABJRU5ErkJggg==';
    $jpeg = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDxyiiiv3E8w//Z';
    $passed = 0;
    function check(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
    function rejects($input): void
    {
        global $service, $directory;
        $before = glob($directory.'/*');
        try { $service->store($input, $directory); } catch (ValidationException $e) {
            check(glob($directory.'/*') === $before, 'Rejected input created a file');
            return;
        }
        throw new RuntimeException('Invalid image accepted');
    }
    function runCase(string $name, callable $test): void
    {
        $test();
        $GLOBALS['passed']++;
        echo "PASS: $name\n";
    }
    try {
        runCase('mobile raw PNG is saved with a random PNG filename', function () use ($service, $directory, $png) {
            $name = $service->store($png, $directory);
            check((bool) preg_match('/^[a-f0-9]{32}\.png$/', $name), 'Unsafe filename');
            check(file_get_contents($directory.'/'.$name) === base64_decode($png), 'Changed image bytes');
        });
        runCase('JPEG content gets a JPEG extension even with a PNG prefix', function () use ($service, $directory, $jpeg) {
            $name = $service->store('data:image/png;base64,'.$jpeg, $directory);
            check(str_ends_with($name, '.jpg'), 'Trusted the claimed MIME type');
            check(getimagesize($directory.'/'.$name)['mime'] === 'image/jpeg', 'Invalid JPEG fixture');
        });
        runCase('multipart image is accepted', function () use ($service, $directory, $png) {
            $source = $directory.'/multipart';
            file_put_contents($source, base64_decode($png));
            $name = $service->store(new UploadedFile($source), $directory);
            check(str_ends_with($name, '.png'), 'Multipart upload rejected');
            rejects(new UploadedFile($source, false));
        });
        runCase('malformed base64 and non-image bytes are rejected', function () {
            foreach (['%%%invalid', base64_encode('<?php echo 1;'), base64_encode('just text'), '', null, []] as $input) { rejects($input); }
        });
        runCase('SVG and GIF content cannot be saved as PNG', function () {
            rejects('data:image/png;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'));
            rejects('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
        });
        runCase('oversized encoded and decoded images are rejected', function () use ($png) {
            rejects(str_repeat('A', 1400000));
            rejects(base64_encode(base64_decode($png).str_repeat('x', 1024000)));
        });
        runCase('image dimension bomb is rejected', function () use ($png) {
            $bytes = base64_decode($png);
            $bytes = substr_replace($bytes, pack('NN', 100000, 100000), 16, 8);
            rejects(base64_encode($bytes));
        });
        echo "$passed profile image regression cases passed.\n";
    } finally {
        foreach (glob($directory.'/*') as $file) { unlink($file); }
        rmdir($directory);
    }
}
