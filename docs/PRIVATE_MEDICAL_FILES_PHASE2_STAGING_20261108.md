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
2. In DoctorApiController::apiAddPrescription, enabling the flag routes **newly uploaded doctor prescription PDFs** to `PrivatePrescriptionStorage` instead of the publicly served directory. The service accepts actual ≤10-MB PDF bytes, requires a PDF magic header, generates a 40-character random hex filename, ensures its storage path is not a public/symlinked directory, secures new files with 0600 mode, and cleans up on a failed database record write.
3. Patient and Doctor appointment/prescription responses, when the flag is on, expose only the existing private authenticated download endpoint, rather than the old public prescription URL. Links carry no bearer token; the mobile app **must** send its existing Care360 token as the `Authorization: Bearer ...` header. That route checks the patient or assigned professional, matching appointment/prescription ownership. The Patient prescription endpoint now returns a controlled 404 when there is no prescription instead of dereferencing `null`.
4. When the flag is OFF, both clients' current public-file link responses and existing uploads remain backward-compatible, **so the public-file exposure is not yet fixed**.
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

Before allowing private mode on staging, back up staging database + references, confirm the secure endpoint's 401/404 and owner/assigned-clinician 200 cases and verify that the mobile PDF viewer supplies an API bearer token. The existing app may currently launch a public URL in a browser/WebView, which **will not** automatically supply authorization. Turning the flag on without updating the installed Flutter clients will cause legitimate PDF downloads to fail. This fail-closed break is preferable to inadvertently publishing new private medical files, but still requires controlled migration.

## Mandatory staged cutover plan

1. **Inventory and classify:** With authorized operator, count legacy public medical references with `medical-files:inventory-safe`. Review the `images/upload` records separately from **public** profile photos, marketing banners and provider galleries, because blocking the whole mixed folder would break public assets.
2. **Backup, scope and hash:** Build a private operator-only mapping of clinical record ID, current file ref, owner, destination, SHA256 hash and migration state. Never place this mapping or document contents in GitHub, logs, chat or public issue comments. Take encrypted backups with tested restore and permission controls.
3. **Extend category coverage:** Separate authenticated and owner-scoped download/write APIs for **pharmacy prescription attachments**, **appointment report images**, **lab/pathology/radiology reports**, and any other patient-uploaded material. Implement role rules for specific pharmacy or lab assignments. The staged writer here addresses **doctor prescription PDF only**.
4. **Upgrade both Flutter apps:** Patient and Plus must retrieve binary files via authenticated HTTPS requests and handle missing/revoked files, local temporary-file encryption/cache cleanup and image/PDF viewers. Never put tokens inside URLs, browser history or third-party viewers.
5. **Stage reversible migration:** Copy verified historical documents into private storage, validate byte hashes and permissions, and update DB references transactionally using a versioned mapping. **Do not delete original public data until backup, restored-file tests, client cutover and webserver plan are verified.** The existing `medical-files:randomize-names` command is *not* a privacy solution and can print filenames in dry-run output; do not substitute it.
6. **Restricted feature enablement:** On a test-only server first, set `K3_PRIVATE_PRESCRIPTION_WRITES=true`; verify new Doctor uploads and authenticated Patient/Doctor downloads. Validate expired, revoked, cross-user, unauthenticated and guessed direct URL attempts. Existing pre-migration PDFs will return 404 from the private endpoint; ensure no silently lost clinical file.
7. **Old URL denial:** After both applications are updated and all clinical content is served privately, arrange explicit `403` or `404` rules for legacy document routes `/prescription/upload/`, `/report_prescription/`, and the *clinical subset* of `/images/upload/`. Verify from an unauthenticated external request, including caches/CDN, backups, staging and production. Do not deny generic public assets until separated.
8. **Security and operations signoff:** Production APP_DEBUG, credentials/Passport keys, payment/OTP/TLS/Agora, audit logs, retention/deletion obligations and role controls are separately verified under #23/#29. Preserve old rollout mapping for rollback without restoring public exposure.

## Release gate

**NO-GO.** No production user files have been inventoried, copied or moved. No Namecheap setting or deployed Laravel configuration has been changed. Synthetic Laravel integration tests are authored but their execution and PHP/CI results must be checked in a suitable runner. Do not activate this flag in production or release new APKs merely because the static contract checks pass.
