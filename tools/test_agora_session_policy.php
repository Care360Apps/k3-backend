<?php
require __DIR__.'/../app/Services/AgoraSessionPolicy.php';
use App\Services\AgoraSessionPolicy;
function expectRtc($ok, $message) { if (!$ok) throw new RuntimeException($message); }
expectRtc(AgoraSessionPolicy::TOKEN_TTL_SECONDS >= 1800, 'Tokens must outlast a standard consultation');
expectRtc(AgoraSessionPolicy::TOKEN_TTL_SECONDS <= 3600, 'Interim token lifetime must remain bounded');
foreach (['DoctorApiController.php', 'UserApiController.php'] as $file) {
    $source = file_get_contents(__DIR__.'/../app/Http/Controllers/'.$file);
    expectRtc(str_contains($source, 'AgoraSessionPolicy::TOKEN_TTL_SECONDS'), "Missing RTC TTL policy: $file");
    expectRtc(! str_contains($source, 'expireTimeInSeconds = 180'), "Old three-minute token remains: $file");
}
echo "PASS Agora token lifetime consistency; app renewal remains a separate release gate.\n";
