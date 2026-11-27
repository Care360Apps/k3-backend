<?php

return [
    // Staging-only opt-in until private-file migration and both Flutter
    // clients' authenticated PDF downloads have passed acceptance tests.
    // Never enable on an installed-app fleet that still expects public URLs.
    'private_prescription_writes' => (bool) env('K3_PRIVATE_PRESCRIPTION_WRITES', false),
    // Synthetic staging only. Never use this flag to run on a live patient database.
    'allow_synthetic_migration' => (bool) env('K3_SYNTHETIC_MEDICAL_MIGRATION', false),
    'private_report_writes' => env('PRIVATE_REPORT_WRITES', false),
];
