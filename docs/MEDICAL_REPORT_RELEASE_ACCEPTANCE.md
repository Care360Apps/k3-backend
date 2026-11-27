# Medical report release acceptance

Status: NOT APPROVED FOR PRODUCTION.

## Staging
Use an isolated database and storage, synthetic accounts and fixture files only.
Stop all writers and workers before maintenance operations. Confirm backups and
restore capability. Apply the ledger schema migration in staging.
Use fixture names care360_fixture_<32 lowercase hex characters>.png.
Run medical:report-migration-audit, medical:migrate-appointment-reports --apply,
medical:report-revocation-readiness and medical:verify-report-ledger.
Record exit codes and aggregate counts. Require at least one migrated fixture.
Run medical:revoke-legacy-reports --apply --confirm-isolated-fixtures only after
confirming isolation and maintenance mode. Verify quarantine SHA-256 against ledger.

## Patient and Plus acceptance
Record exact app build and backend commit. Test authorized patient and assigned
approved provider downloads, unrelated user denial, anonymous denial, invalid
filename denial, expired session, upload and subsequent retrieval, and legacy
public URL denial over HTTP (including CDN/cache). Check no-store headers.
Test free and paid appointment booking regressions, OTP and login.
Do not use real medical records in fixtures.

## Recovery
On failure stop rollout. Preserve ledger, private copies and quarantine.
Restore only verified synthetic fixture copies in isolated staging when required.
Never restore public access to real medical records as an automatic rollback.

## Production gate
Production migration and revocation are not implemented for real files.
Require verified ownership across shared upload consumers, tested backups,
approved maintenance window, explicit authorization, and successful staging
acceptance before enabling any production operation.
No production activation is authorized by merging this PR.
