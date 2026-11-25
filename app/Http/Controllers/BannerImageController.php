<?php
namespace App\Http\Controllers;

use App\Models\Banner;
use App\Services\SafePartnerLink;

class BannerImageController extends Controller
{
    public function show(int $id)
    {
        $now = now();
        $banner = Banner::where('status', 1)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $now->toDateString()))
            ->findOrFail($id);
        abort_unless(SafePartnerLink::isValidHttps($banner->link), 404);
        $name = $banner->image;
        abort_unless(is_string($name) && preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,199}\.(jpe?g|png)$/iD', $name), 404);
        $root = realpath(public_path('images/upload'));
        $source = public_path('images/upload/'.$name);
        $file = realpath($source);
        abort_unless($root && $file && !is_link($source) && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file) && is_readable($file), 404);
        $mime = (@getimagesize($file))['mime'] ?? '';
        abort_unless(in_array($mime, ['image/jpeg','image/png'], true), 404);
        return response()->file($file, ['Content-Type'=>$mime,'Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
