<?php

return [
    // Staging-only opt-in until private-file migration and both Flutter
    // clients' authenticated PDF downloads have passed acceptance tests.
    // Never enable on an installed-app fleet that still expects public URLs.
    'private_prescription_writes' => (bool) env('K3_PRIVATE_PRESCRIPTION_WRITES', false),
];
