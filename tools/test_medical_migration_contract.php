<?php

/**
 * Offline source-shape gates. No application key, patient data, database
 * connection or public medical file is ever opened by this test.
 */
$root = dirname(__DIR__);
$paths = [
    'command' => 'app/Console/Commands/StageLegacyPrescriptions.php',
    'ledger' => 'database/migrations/2026_11_08_060000_create_medical_prescription_migration_ledger.php',
    'config' => 'config/medical_files.php',
    'doctor' => 'app/Http/Controllers/DoctorApiController.php',
    'download' => 'app/Http/Controllers/PatientMedicalFileController.php',
    'feature' => 'tests/Feature/LegacyPrescriptionStagingTest.php',
];
$contents = [];
foreach ($paths as $label => $file) {
    $contents[$label] = file_get_contents($root.'/'.$file);
    if ($contents[$label] === false) {
        fwrite(STDERR, "FAIL: missing file: $label\n");
        exit(1);
    }
}
$checks = [
    'Synthetic migration disabled by default' =>
        str_contains($contents['config'], "env('K3_SYNTHETIC_MEDICAL_MIGRATION', false)"),
    'Command forbids production and requires synthetic confirmation' =>
        str_contains($contents['command'], "app()->environment('production')")
        && str_contains($contents['command'], "confirm-synthetic-staging")
        && str_contains($contents['command'], "allow_synthetic_migration"),
    'Default is dry-run and no destructive source deletion' =>
        str_contains($contents['command'], "if ($"."apply)")
        && ! str_contains($contents['command'], "unlink($"."source")
        && str_contains($contents['command'], "Original public documents remain intact"),
    'Staged copy is hash-checked and records updated transactionally' =>
        str_contains($contents['command'], "hash_file('sha256', $".'source)')
        && str_contains($contents['command'], "DB::transaction(")
        && str_contains($contents['command'], "->where('pdf', $"."legacy)"),
    'Rollback requires original hash and keeps private copy' =>
        str_contains($contents['command'], "hash_equals((string) $"."row->sha256")
        && str_contains($contents['command'], "'rolled_back'")
        && str_contains($contents['command'], "never unlink a clinical file here"),
    'Ledger cannot be dropped with entries present' =>
        str_contains($contents['ledger'], "DB::table('medical_prescription_migrations')->exists()"),
    'Doctor prescription writes reject inactive accounts and malformed JSON' =>
        str_contains($contents['doctor'], "(int) $"."prescriber->status !== 1")
        && str_contains($contents['doctor'], "'medicines' => 'bail|required|json'")
        && str_contains($contents['doctor'], "count($"."decoded) > 50"),
    'Private prescription download excludes unapproved providers' =>
        str_contains($contents['download'], "orWhere('user_role', '!=', 'provider')")
        && str_contains($contents['download'], "\$user->hasRole('doctor')"),
    'Synthetic integration tests include round-trip, dry-run and invalid PDF' =>
        str_contains($contents['feature'], "test_copy_update_and_rollback_preserve_original")
        && str_contains($contents['feature'], "test_dry_run_never_changes_records")
        && str_contains($contents['feature'], "test_missing_or_bad_pdf_is_skipped"),
];
$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS: ' : 'FAIL: ').$name.PHP_EOL;
    if (! $ok) {
        $failed++;
    }
}
exit($failed === 0 ? 0 : 1);
