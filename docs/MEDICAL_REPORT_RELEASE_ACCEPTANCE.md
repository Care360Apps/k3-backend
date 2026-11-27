# Medical report release acceptance

Production activation: DISABLED. Merging or deploying source is not activation approval.

## Implemented boundaries

The existing appointment PNG report storage and authenticated Patient/Plus routes are reused. Migration preserves PNG bytes and the existing 6 MB API upload limit, creates random private names and writes SHA-256 evidence in the same transaction as appointment references. Unsupported metadata, missing/changed files and shared public upload references block progress. Lab results and prescription PDFs keep their separate existing protected workflows; this tool does not convert arbitrary clinical documents.

One storage lock is shared by report migration, private uploads and reconciliation. Web, admin and API report upload paths use the private writer when activated. Production private writes require a matching, expiring operator approval, not only an environment switch. Source files are never removed before a hash-verified recovery copy exists. Restarting revocation verifies evidence instead of overwriting it. Recovery recreates missing PRIVATE copies and never automatically restores a public clinical URL or overwrites a conflicting copy.

Daily reconciliation records orphan observations. Quarantine requires two observations separated by at least seven days, an old file, no appointment reference, no migration-ledger reference, maintenance and authorization. No automatic permanent deletion exists. Automatic quarantine defaults OFF.

## One isolated staging procedure

Prepare a NEW HTTPS staging host and a pre-provisioned, schema-compatible database containing synthetic accounts/records only. Do not clone real patient content. Use a separate APP_KEY, database and host; disable real email, background delivery and financial activation. Keep the staging environment file outside all web roots with mode 0600. Include APP_ENV=staging, APP_DEBUG=false, K3_SYNTHETIC_MEDICAL_MIGRATION=true, PRIVATE_REPORT_WRITES=true, MEDICAL_REPORT_PRODUCTION_ENABLED=false, MEDICAL_REPORT_AUTO_QUARANTINE=false, MAIL_MAILER=array and QUEUE_CONNECTION=sync. Configure MEDICAL_REPORT_REVOCATION_ORIGINS with the staging HTTPS origin and every CDN/alternate origin that can serve the upload path. No secrets should be sent in chat.

Upload/extract the supplied package OUTSIDE the live directory and run:

```sh
php /home/ghostsmaw/care360-medical-release/tools/medical_report_stage.php --target=/home/ghostsmaw/care360-staging/medical-release --env-file=/home/ghostsmaw/care360-staging.env --live-root=/home/ghostsmaw/care360-live
```

The installer reads live environment values only to reject reuse; it never writes into live. It verifies package hashes, stages into a new sibling directory, makes a private staging database backup and environment backup, installs LOCKED dependencies, checks PHP syntax, applies only the two report-evidence migrations and clears staging caches. Failed/interrupted targets remain for investigation; choose a new target instead of deleting evidence. A completed identical deployment can be rechecked without overwriting it. Set the staging host document root to the new target's public directory. The installer does not seed medical records, migrate files or approve activation.

The database backup covers base tables; restoring its SQL into a separate empty synthetic database is tested. For production, independently verified full database/schema and private-file backups, including any views/routines and restoration procedure, remain a prerequisite. No production backup or restore has been performed by this PR.

## Synthetic acceptance on staging

1. Create synthetic patient, assigned approved professional, unrelated patient/professional and unapproved professional accounts through the existing test/onboarding process. Put only synthetic legacy PNG fixtures into staging uploads and link them to synthetic appointments. Record backend commit and exact app versions.
2. Stop writers/workers; enter maintenance. Run `php artisan medical:report-migration-audit`, then `php artisan medical:migrate-appointment-reports --apply`, `php artisan medical:verify-report-ledger` and `php artisan medical:report-revocation-readiness`. Require nonzero fixture coverage and no failures.
3. Run `php artisan medical:revoke-legacy-reports --apply`. Run it again to verify repeatability. Verify original paths absent and private/quarantine hashes match the ledger.
4. Run `php artisan medical:report-release verify-http`. This sends unauthenticated normal AND cache-revalidation GETs to the exact legacy URLs at every configured origin. Only 401/403/404/410 count as denial. Redirects, 2xx, 5xx, timeouts and empty ledgers fail. Purge/invalidate CDN objects and correct aliases if any URL still serves content; rerun until every origin denies. Do not print medical URLs in public logs.
5. On an isolated copy, remove a synthetic private report, run `php artisan medical:report-release recover` and verify its content/hash and authenticated retrieval. Public URLs must remain absent. Conflicting copies are retained for operator investigation, never overwritten automatically.
6. Exercise `php artisan medical:report-release reconcile` and `php artisan medical:report-orphan-quarantine --apply` with aged unreferenced synthetic files. Verify recent, referenced and ledger-backed files remain. Test recovery and interruption/restart.
7. Resume isolated staging service for Patient and Plus. Verify own-patient and assigned-approved-provider retrieval, stranger/unapproved/suspended-user denial, expired session, invalid scope/URL, upload followed by retrieval, PNG size/dimension limits, retry, no-store responses, no credential forwarding/redirects and hiding report pixels when backgrounded. Confirm OTP, sign-in, appointment, free booking, paid-payment verification and existing prescriptions/lab records. These host/device checks require the operator; local tests and CI are not a substitute.

## Production gate (not authorized)

Defaults remain MEDICAL_REPORT_PRODUCTION_ENABLED=false and MEDICAL_REPORT_AUTO_QUARANTINE=false. No generated package contains credentials or an approved activation file.

After successful recorded staging acceptance and EXPLICIT owner approval, a production operator must independently verify backups, stopped writers, deployment parity and a maintenance window. A mode-0600 approval JSON outside the public root must match `medical:report-release target`, deployment_id, the configured 64-hex staging acceptance SHA-256, a future expires_at, explicit action scopes and approved/backup_restore_verified/writers_stopped booleans. Required configuration keys are documented in config/medical_files.php. Keep activate scope separate until the operator has completed production migration/revocation/HTTP checks. Expired/mismatched/missing approvals fail closed. Do not enable flags merely because CI passed.

## Rollback and limits

Retain the ledger, operation journal, private files, quarantine and staging backups. Do not roll back evidence migrations, delete quarantines or restore public access to real records. On source deployment failure restore the prior source/config from verified backups while retaining private storage and compatible authenticated readers. Investigate a conflicting private file before recovery; do not overwrite evidence. Report/file state machines preserve recoverable copies across interrupted DB/filesystem operations, but cannot make a filesystem and SQL transaction globally atomic. Shared locks, maintenance, durable evidence and restart tests cover that boundary.

Outstanding external acceptance: actual staging host, CDN/aliases, production configuration/filesystem parity and both real-device sessions. No live file was migrated or revoked during development.
