<?php
// Static safety regression for the Patient's nearby-care marketplace.
// No real patient locations, private keys or production data are used.
$controller = file_get_contents(__DIR__.'/../app/Http/Controllers/UserApiController.php');
if ($controller === false) {
    throw new RuntimeException('Cannot locate provider-discovery controller.');
}
function nearbyMethod(string $source, string $from, string $to): string
{
    $begin = strpos($source, $from);
    $end = $begin === false ? false : strpos($source, $to, $begin + strlen($from));
    if ($begin === false || $end === false) {
        throw new RuntimeException('Provider-discovery method contract changed.');
    }
    return substr($source, $begin, $end - $begin);
}
$listing = nearbyMethod($controller, 'public function apiProviders(', 'public function apiSingleProvider(');
$detail = nearbyMethod($controller, 'public function apiSingleProvider(', 'public function getNearProvider(');
$near = nearbyMethod($controller, 'public function getNearProvider(', 'public function apiSingleDoctor(');
foreach (["->where('user_role', 'provider')", "->where('is_approved', 1)",
          "->where('is_filled', 1)", 'whereSubscriptionStatus(1)'] as $required) {
    if (!str_contains($listing, $required)) {
        throw new RuntimeException('Unapproved or incomplete provider profiles could be listed.');
    }
}
foreach (['$provider->status', '$provider->is_approved', '$provider->is_filled',
          '$provider->subscription_status'] as $required) {
    if (!str_contains($detail, $required)) {
        throw new RuntimeException('Unapproved provider details could be opened.');
    }
}
if (!str_contains($near, '$p->distance_km = null;') ||
    str_contains($near, "(float) ($p->lat ?? 0)") ||
    !str_contains($listing, "'limit' => 'bail|nullable|integer|min:1|max:50'")) {
    throw new RuntimeException('Missing/invalid provider coordinates should not appear at 0,0.');
}
echo 'PASS: approved-provider discovery and missing-location contract'.PHP_EOL;
