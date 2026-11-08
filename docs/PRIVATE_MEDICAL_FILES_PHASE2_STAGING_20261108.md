# Care360 medical-file privacy — Phase 2 staged write transition

**Date:** 8 November 2026  
**Status:** DRAFT. NOT DEPLOYED. PRIVATE-PDF FLAG OFF BY DEFAULT.  
**Owner:** Care360 infrastructure/security operator for production decisions.  
**Tracking:** backend issues #22 (public clinical media), #23 (server secrets/security), #29 (combined release gate).

## Current finding and limitation

The old Laravel code writes patient prescriptions to `public/prescription/upload`, pharmacy-related PDFs to the same directory, laboratory paperwork to `public/report_prescription/...`, and patient appointment report images to `public/images/upload`. Those URLs are directly accessible unless a separately verified production webserver rule blocks them. Random filenames are not permission checks.

Phase 1 added an authenticated appointment-prescription downloader in `PatientMedicalFileController`. It did **not** change new-file writes, old mobile viewers or the installed public URL access. That security finding remains OPEN.

## What this staged branch now introduces

1. `config/medical_files.php` explicitly defaults `K3_PRIVATE_PRESCRIPTION_WRITES=false`.
2. In DoctorApiController::apiAddPrescription, enabling the flag routes **newly uploaded doctor prescription PDFs** to `PrivatePrescriptionStorage` instead of the publicly served directory. The service accepts actual ≤10-MB PDF bytes, requires a PDF magic header, generates a versioned `k3priv_` filename with 40 cryptographically random hex characters, ensures its storage path is not a public/symlinked directory, secures new files with 0600 mode, and cleans up on a failed database record write.
3. Patient and Doctor appointment/prescription responses distinguish **each document by its filename provenance**. New `k3priv_<40hex>.pdf` files always return the authenticated appointment endpoint, even after the feature flag is switched off. Existing legacy 32-hex public files continue returning their original URLs until individually migrated; flipping the flag does not make older documents suddenly return 404. The private download requires `Authorization: Bearer ...` and checks patient ownership or an **approved, active, assigned** professional. The Patient prescription endpoint returns a controlled 404 when the record is missing.
4. When the flag is OFF, **new writes** still use the old public directory, while documents already stored privately **stay on authenticated URLs**. Older public-file links remain accessible until each file is migrated and the public URL is denied; this exposure is **not yet fixed**.
5. `medical-files:inventory-safe` can count DB references for prescriptions, medicine-order PDFs, lab/report PDFs, and appointment report images. It outputs only aggregated counts (no filenames, paths, IDs, file contents, document hashes or patient information). In production, only an authorized operator should use its explicit `--production-read-only` option. It makes no database changes and opens no clinical files.

## Local or isolated staging test commands

Only in a test environment with synthetic records:

```sh
php tools/test_private_medical_download_contract.php
php tools/test_private_prescription_write_contract.php
php artisan test --filter=PrivatePrescriptionWriteTest
php artisan test --filter=PrivatePrescriptionAccessTest
php artisan medical-files:inventory-safe
```

Before allowing private mode on staging, back up staging database + references, confirm the secure endpoint's 401/404 and owner/assigned-clinician 200 cases and verify that the mobile PDF viewer supplies an API bearer token. The existing app may currently launch a public URL in a browser/WebView, which **will not** automatically supply authorization. Turning the flag on without updating the installed Flutter clients will cause **new private PDF downloads** to fail; legacy records retain public links but remain exposed. This fail-closed break is preferable to inadvertently publishing new private medical files, but still requires controlled migration.

## Mandatory staged cutover plan

1. **Inventory and classify:** With authorized operator, count legacy public medical references with `medical-files:inventory-safe`. Review the `images/upload` records separately from **public** profile photos, marketing banners and provider galleries, because blocking the whole mixed folder would break public assets.
2. **Backup, scope and hash:** Build a private operator-only mapping of clinical record ID, current file ref, owner, destination, SHA256 hash and migration state. Never place this mapping or document contents in GitHub, logs, chat or public issue comments. Take encrypted backups with tested restore and permission controls.
3. **Extend category coverage:** Separate authenticated and owner-scoped download/write APIs for **pharmacy prescription attachments**, **appointment report images**, **lab/pathology/radiology reports**, and any other patient-uploaded material. Implement role rules for specific pharmacy or lab assignments. The staged writer here addresses **doctor prescription PDF only**.
4. **Upgrade both Flutter apps:** Patient and Plus must retrieve binary files via authenticated HTTPS requests and handle missing/revoked files, local temporary-file encryption/cache cleanup and image/PDF viewers. Never put tokens inside URLs, browser history or third-party viewers.
5. **Stage reversible migration:** Copy verified historical documents into private storage under new `k3priv_<40hex>.pdf` filenames, validate byte hashes and permissions, and update DB references transactionally using a versioned mapping. The private filename prefix is deliberately distinct from legacy 32-hex public names, so an operator can migrate records incrementally and independently of the write flag. **Do not delete original public data until backup, restored-file tests, client cutover and webserver plan are verified.** The existing `medical-files:randomize-names` command is *not* a privacy solution and can print filenames in dry-run output; do not substitute it.
6. **Restricted feature enablement:** On a test-only server first, set `K3_PRIVATE_PRESCRIPTION_WRITES=true`; verify new Doctor uploads and authenticated Patient/Doctor downloads, then switch the flag OFF and confirm **existing private files still require authentication while legacy files retain their old links**. Validate expired, revoked, cross-user, unauthenticated and guessed direct URL attempts. Existing pre-migration PDFs will return 404 from the private endpoint; ensure no silently lost clinical file.
7. **Old URL denial:** After both applications are updated and all clinical content is served privately, arrange explicit `403` or `404` rules for legacy document routes `/prescription/upload/`, `/report_prescription/`, and the *clinical subset* of `/images/upload/`. Verify from an unauthenticated external request, including caches/CDN, backups, staging and production. Do not deny generic public assets until separated.
8. **Security and operations signoff:** Production APP_DEBUG, credentials/Passport keys, payment/OTP/TLS/Agora, audit logs, retention/deletion obligations and role controls are separately verified under #23/#29. Preserve old rollout mapping for rollback without restoring public exposure.

## Release gate

**NO-GO.** No production user files have been inventoried, copied or moved. No Namecheap setting or deployed Laravel configuration has been changed. Synthetic Laravel integration tests are authored but their execution and PHP/CI results must be checked in a suitable runner. Do not activate this flag in production or release new APKs merely because the static contract checks pass.
