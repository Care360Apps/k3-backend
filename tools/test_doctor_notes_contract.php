<?php
// Offline source-contract checks. Full auth/migration integration tests are needed
// against an isolated Laravel DB before this clinical feature can ship.
function ensure($ok, $message): void {
    if (! $ok) throw new RuntimeException($message);
}
$root = __DIR__.'/../';
$c = file_get_contents($root.'app/Http/Controllers/DoctorNoteController.php');
$m = file_get_contents($root.'app/Models/DoctorNote.php');
$r = file_get_contents($root.'routes/api.php');
$schema = file_get_contents($root.'database/migrations/2026_11_04_000001_create_doctor_notes_table.php');

ensure(str_contains($c, "hasRole('doctor')"), 'Only doctors can access notes');
ensure(str_contains($c, "->where('is_approved', 1)"), 'Approval required');
ensure(str_contains($c, "Appointment::where('doctor_id', \$doctor->id)"), 'Appointment ownership required');
ensure(str_contains($c, "DoctorNote::where('doctor_id', \$doctor->id)"), 'Note ownership required');
ensure(str_contains($c, "'user_id' => \$appointment->user_id"), 'Patient ID must come from owned appointment');
ensure(str_contains($c, "if (\$note->status === 'final')"), 'Signed/finalized note cannot be edited');
ensure(str_contains($c, "->lockForUpdate()"), 'Note finalization must be concurrency-safe');
ensure(str_contains($c, "'Cache-Control', 'private, no-store'"), 'Clinical responses must not be cached');
ensure(str_contains($m, "'body' => 'encrypted'") && str_contains($m, "'title' => 'encrypted'"), 'Note contents must use encrypted casts');
ensure(str_contains($schema, "Schema::create('doctor_notes'"), 'Notes schema must exist');
ensure(str_contains($r, "Route::post('doctor_notes'") && str_contains($r, "Route::put('doctor_notes/{id}'"), 'Authenticated note API routes missing');
ensure(str_contains($c, "Clinical notes are retained for audit"), 'No hard-delete on clinical notes');
echo "PASS doctor note access-control, encrypted content, finalization and retention contract\n";
