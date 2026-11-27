<?php

return [
    // Staging-only opt-in until private-file migration and both Flutter
    // clients' authenticated PDF downloads have passed acceptance tests.
    // Never enable on an installed-app fleet that still expects public URLs.
    'private_prescription_writes' => (bool) env('K3_PRIVATE_PRESCRIPTION_WRITES', false),
    // Synthetic staging only. Never use this flag to run on a live patient database.
    'allow_synthetic_migration' => (bool) env('K3_SYNTHETIC_MEDICAL_MIGRATION', false),
    'private_report_writes' => (bool) env('PRIVATE_REPORT_WRITES', false),
    'production_release_enabled' => (bool) env('MEDICAL_REPORT_PRODUCTION_ENABLED', false),
    'release_approval_file' => env('MEDICAL_REPORT_APPROVAL_FILE'),
    'staging_acceptance_sha256' => env('MEDICAL_REPORT_STAGING_ACCEPTANCE_SHA256'),
    'deployment_id' => env('MEDICAL_REPORT_DEPLOYMENT_ID'),
    'auto_quarantine' => (bool) env('MEDICAL_REPORT_AUTO_QUARANTINE', false),
    'revocation_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEDICAL_REPORT_REVOCATION_ORIGINS', env('APP_URL', '')))))),
];
