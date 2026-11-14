<?php

return [
    // Enable only after migration, privacy approval and complete staging journeys.
    'deletion_policy_approved' => (bool) env('K3_CAREER_DELETION_POLICY_APPROVED', false),
    'enabled' => (bool) env('K3_CAREER_WORKFLOWS', false),
];
