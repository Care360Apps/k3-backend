# Care360 Patient + Plus — secured developer handover gate
Date: 8 November 2026
Decision: **NO-GO for production**. No release APK and no clinical migration performed.

## Existing verified work
- Patient clinical downloads draft PR #46 (Care360Apps/k3-patient): bearer-authenticated appointment-specific PDF requests to own API, size/type/signature checks, redirected/cross-origin URL denial, explicit user-file-picker saving, functional Appointment Details PDF button and temporary legacy link compatibility.
- Patient public isolated Flutter QA: **56/56 PASS**, run https://github.com/speakoutMentalHealth/care360-android-builder/actions/runs/42450795366; test source SHA `9159cee82d9891e4b17e17f0e8a53e3c9825d4ab`.
- Plus private prescription draft PR #34 (Care360Apps/k3-doctor): iOS Bearer token corrected, both platforms reject failed server HTTP/JSON responses, clinician Patient Information PDF opens via session-authenticated in-memory viewer with outbound links disabled.
- Backend draft PR #32 (Care360Apps/k3-backend): private prescription write flag remains OFF; protected read route; PHI-safe inventory and production preflight read-only commands; legacy public media remain unmigrated.

## CI blockers and access control
Private Plus and Laravel Actions have not yielded usable passing test evidence. A public repo source-quality fallback was prepared but requires separate fine-grained read-only GitHub tokens. Never store tokens in code or send in chat.

Repository: `speakoutMentalHealth/care360-android-builder`

Required Actions secrets in **that public builder repository only**:
1. `CARE360_BACKEND_SOURCE_READ_TOKEN` — fine-grained token, access to **Care360Apps/k3-backend only**, Contents READ.
2. `CARE360_DOCTOR_SOURCE_READ_TOKEN` — separate fine-grained token, access to **Care360Apps/k3-doctor only**, Contents READ.
3. `K3_BUILD_OUTPUT_PASSWORD` — independent strong password for encrypting private diagnostics; do not paste in chats.

The first two are missing from the most recent protected-runner verification; the job fails safely at preflight before checking out a private repo. Once configured, re-run the **failed** protected jobs:
- Backend QA https://github.com/speakoutMentalHealth/care360-android-builder/actions/workflows/care360-backend-quality-review.yml
- Plus QA https://github.com/speakoutMentalHealth/care360-android-builder/actions/workflows/care360-plus-quality-review.yml

A read-only GitHub connector available inside ChatGPT does **not** automatically grant public GitHub Actions runners access to private source; passing the connector's credentials across services is not supported.

## 8 November safety corrections — staged, not deployed

- **Mixed-mode prescription routing:** New private records are labeled `k3priv_<40 hex characters>.pdf`. API responses choose the authenticated private route **per record**, independently of whether `K3_PRIVATE_PRESCRIPTION_WRITES` is currently on. Older public records retain their legacy links until individually migrated. Turning the flag off cannot publish a previously private record. Invalid `k3priv_` filenames fail closed instead of falling back to public URLs.
- **Professional access revocation:** Download authorization also checks the assigned clinician is currently approved and active. A previously assigned but suspended or unapproved professional must receive 404, with synthetic regression coverage.
- **Plus prescription acknowledgement:** PDF uploads now require server HTTP success **and** JSON `success:true`, and the Patient Information screen must await that result before showing a success message. Newly generated PDFs are sent and previewed from memory; no shared Downloads or persistent generated-PDF file is required. Existing professional upload/clinical role rules still require staging acceptance.
- **Synthetic tests:** `php tools/test_private_prescription_urls_runtime.php` is a Laravel-free runtime selector test added to private backend CI; Plus has updated source/widget regression assertions for no false-success or persisted PDF. A separately reconstructed PHP selector harness passed 56 synthetic route/filename cases locally; this does **not** replace running the actual private repository's CI and full Laravel authorization tests.
- **Testing remains blocked:** public encrypted Plus and backend workflows still lack their separate read-only private-source tokens. Do not merge/deploy merely because local selector logic or Patient Flutter source tests pass.

## Mandatory patient data protection acceptance
1. Keep any publicly accessible clinical document URLs confidential; do not share sample medical files or access tokens in issues or screenshots.
2. Dry-run `php artisan medical-files:inventory-safe` on a synthetic staging database first, then via authorized operator against protected production data only when approved.
3. Classify all legacy patient prescription PDFs, medicine order attachments, laboratory reports and clinical images separately from public avatars/marketing assets. Encrypt backup and test restore before copy or URL cutover.
4. New doctor prescription writes: enable `K3_PRIVATE_PRESCRIPTION_WRITES` **only** on isolated TLS staging with synthetic records; verify file owner/mode, clean error paths and no public URL. Existing production remains OFF.
5. Test POST new prescription, patient own GET=200, assigned doctor GET=200, stranger/nonassigned clinician GET=404, no token=401, malformed PDF=422, missing file=404, expired/revoked token=401 and redirect refusal in both apps.
6. Migrate old clinical media with checksummed private copies and a reversible mapping. Update pharmacy/lab/report-image routes and secure viewers, not merely doctor prescriptions.
7. Externally verify old clinical file HTTP addresses are 403/404 without auth, with CDN/cache purged, while non-medical public assets still load. Do not delete originals before verified backups.
8. Verify production runtime under authorized operator: `php artisan care360:security-preflight` (reports booleans only, no secrets), API TLS, APP_DEBUG disabled, Passport/private key ACLs and audited credentials rotation.
9. Verify OTP attempts/expiry, payment gateway real verification, subscription/invoice ownership, role-scoped professional access, Agora sessions, secure account deletion and prior security report H7 missing description.
10. Validate verified graduate/employer Workforce pilot separately with school/role credentials; keep flag OFF until medical privacy and legal/licensing compliance are approved.

## Final Android delivery (original developer handles store publishing)
- Merge only after all dependent PR checks, security approvals, migration and mobile API compatibility pass.
- Run one combined source-build test using a staging-only app configuration and fictional patient, doctor, provider, pharmacy, graduate and verified employer accounts.
- BlueStacks + physical Android checks: first launch, 5 languages/no Flutter crash, card routing, appointments, consultations, medical documents, AI with safe fallback, Maps without exposed client key, sponsor carousel, Workforce disabled/enabled restrictions and logout.
- Provide private source ZIPs (Patient, Plus, Laravel separately), dependency lockfiles, migration plan, no credentials/PHI, SHA256 manifest, test evidence, known issues and developer-specific signing responsibility. Never send upload signing keystore through open chat/GitHub.
- Original developer builds/signs/publishes Play Store AAB and handles iOS TestFlight/App Store as separately authorized.

## Remaining risk declaration
**No new build has been verified against deployed Namecheap services.** Source-level Patient tests are not evidence that public medical files were removed or credentials rotated. Plus and backend tests await a runnable private-source environment. The overall handover percentage should not be increased solely due to draft source changes.
