<?php

return [
    // Production default OFF until medical-data security and workforce
    // verification/recruitment licensing requirements have been signed off.
    'enabled' => (bool) env('K3_WORKFORCE_PILOT_ENABLED', false),
    'session_hours' => 12,
    'minimum_age' => 18,
];
