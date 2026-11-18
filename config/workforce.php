<?php

return [
    // Production default OFF until medical-data security and workforce
    // verification/recruitment licensing requirements have been signed off.
    'enabled' => (bool) env('K3_WORKFORCE_PILOT_ENABLED', false),
    'local_demo' => (bool) env('K3_WORKFORCE_LOCAL_DEMO', false),
    'notice_url'=>(string)env('K3_WORKFORCE_PRIVACY_NOTICE_URL',''),
    'notice_version'=>(string)env('K3_WORKFORCE_PRIVACY_NOTICE_VERSION',''),
    'notice_approved'=>(bool)env('K3_WORKFORCE_NOTICE_APPROVED',false),
    'session_hours' => 12,
    'minimum_age' => 18,
];
