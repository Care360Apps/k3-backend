# Appointment report release gates

Do not enable private writes on production until signed mobile clients and staging acceptance are verified.

## Synthetic staging

Use an isolated database and synthetic PNG files only. Run the audit, then enable K3_SYNTHETIC_MEDICAL_MIGRATION and run medical:migrate-appointment-reports --apply. Repeat the audit: eligible, invalid, missing and malformed must all be zero. Verify hashes and repeat migration for idempotency.

Test authenticated GET for the owner and assigned approved professional (200, PNG, no-store), unrelated users (404), inactive users and unauthenticated requests (denied), wrong appointment and filename (404). Test both mobile viewers, malformed and oversized responses, redirect rejection, and expired sessions.

## Public revocation

The images/upload directory is shared; never block the whole directory or delete files by extension. Inventory exact legacy report filenames before migration, verify no other application references, back up and hash originals, then move only verified report originals outside the document root in a maintenance window. Confirm the old URLs return 403 or 404 through the actual hosting server and CDN, including cached copies. If any old URL remains accessible, stop release. Do not purge backups until retention approval.

## Production

Production migration is currently blocked by the command. A reviewed production-specific migration and rollback procedure, backup restoration test, signed client rollout, and explicit release approval are required. Do not enable PRIVATE_REPORT_WRITES until these gates are satisfied. Record actual staging results and deployment evidence; source CI alone is insufficient.
