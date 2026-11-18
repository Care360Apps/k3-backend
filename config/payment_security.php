<?php

return [
    // Enabling this requires deploying the receipt-claims migration first,
    // backfilling and reviewing legacy paid receipts, then testing concurrency
    // against the staging database. Disabled until release authorization.
    'atomic_receipt_claims' => (bool) env('K3_ATOMIC_PAYMENT_RECEIPTS', false),
];
