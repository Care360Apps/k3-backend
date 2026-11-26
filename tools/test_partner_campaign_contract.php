<?php

require __DIR__.'/../app/Services/SafePartnerLink.php';
use App\Services\SafePartnerLink as Link;

function checkPartner(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

foreach ([
    'javascript:alert(1)', 'http://partner.example.org/',
    'https://localhost/', 'https://127.0.0.1:8000/',
    'https://user:password@partner.example.org',
    'https://partner.example.org:8443/',
    'https://site.local/', 'https://192.168.0.101/',
] as $bad) {
    checkPartner(! Link::isValidHttps($bad), "Unsafe partner destination accepted: $bad");
}
checkPartner(Link::isValidHttps('https://partner.example.org/offer?campaign=1'), 'Valid public HTTPS partner blocked');

$root = __DIR__.'/../';
$api = file_get_contents($root.'app/Http/Controllers/UserApiController.php');
$admin = file_get_contents($root.'app/Http/Controllers/SuperAdmin/BannerController.php');
$migration = file_get_contents($root.'database/migrations/2026_11_04_000002_add_partner_campaign_fields_to_banner.php');
checkPartner(str_contains($api, "Banner::where('status', 1)"), 'Inactive banners leaked');
checkPartner(str_contains($api, "whereNull('starts_at')"), 'Missing start schedule');
checkPartner(str_contains($api, "whereNull('ends_at')"), 'Missing end schedule');
checkPartner(str_contains($api, 'SafePartnerLink::isValidHttps'), 'Old unsafe URLs leaked');
checkPartner(! str_contains($admin, "count(Banner::orderBy('id', 'DESC')->get()) < 3"), 'Old limit of three campaigns remains');
checkPartner(str_contains($admin, "Gate::denies('banner_add')"), 'Create permission missing');
checkPartner(str_contains($admin, "Gate::denies('banner_edit')"), 'Edit permission missing');
checkPartner(str_contains($admin, "Gate::denies('banner_delete')"), 'Delete permission missing');
checkPartner(str_contains($migration, "'is_sponsored'"), 'Sponsored classification missing');
checkPartner(str_contains($migration, "'starts_at'"), 'Campaign start date missing');
checkPartner(str_contains($migration, "'ends_at'"), 'Campaign expiry missing');

echo "PASS public HTTPS campaign safety, date windows, unlimited admin and sponsorship disclosure.\n";
