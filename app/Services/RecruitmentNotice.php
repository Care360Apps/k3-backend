<?php

namespace App\Services;

use Illuminate\Http\Request;

final class RecruitmentNotice
{
    public static function local(Request $r): bool
    {
        return config('workforce.local_demo', false) && app()->environment('local', 'testing')
            && in_array($r->ip(), ['127.0.0.1', '::1'], true);
    }

    public static function details(Request $r): ?array
    {
        if (self::local($r)) {
            return ['version' => 'local-demo-v1', 'url' => null];
        }
        if (! config('workforce.notice_approved', false)) {
            return null;
        }
        $url = (string) config('workforce.notice_url', '');
        $u = parse_url($url);
        $version = (string) config('workforce.notice_version', '');
        $host = strtolower($u['host'] ?? '');
        if (! is_array($u) || ($u['scheme'] ?? '') !== 'https' || ! ($host === 'care360apps.com' || str_ends_with($host, '.care360apps.com'))
            || trim($u['path'] ?? '', '/') === '' || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])
            || (isset($u['port']) && $u['port'] !== 443) || ! preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $version)) {
            return null;
        }

        return ['version' => $version, 'url' => $url];
    }
}
