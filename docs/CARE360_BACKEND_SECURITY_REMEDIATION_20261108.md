# Care360 Laravel backend — security remediation and release decision

**Review date:** 2026-11-08  
**Source:** Care360Apps/k3-backend, stacked draft PR #32 over #31 and #28  
**State:** SOURCE CANDIDATE, NO-GO for production. No production migration, server access, credential rotation or deployment.  
**Related:** issues #22, #23, #29, #30; October 9, 2026 security assessment; docs/CARE360_SECURITY_20261009_VERIFICATION_MATRIX.md.

## Security matrix

| Finding / threat | Concrete source work | Status | Mandatory closure test or action |
|---|---|---|---|
| H1 Credential compromise and vendor archive | Existing safe config/preflight, no credentials added; upstream gateway exceptions now sanitized in PaymentApiController | OPEN / operator | Inventory recipients, revoke access, securely rotate DB/SMTP/gateway/Agora/Firebase/Maps/OneSignal secrets, plan Laravel APP_KEY encrypted-data rewrap and Passport key/session invalidation; never post values |
| H2 Laravel production debug, key permissions, HTTPS, unsafe logging | Read-only care360:security-preflight; new Stripe logs avoid raw exception text | PARTIAL | Authorized Namecheap operator verifies deployed environment, .env/keys, webroot, TLS and actual logging with redacted evidence |
| H3 Professional self-promotion / unauthorized access | Existing Doctor SELF_EDITABLE; PR #32 continuation rejects inactive prescriber, approved clinician required to view professional appointment, provider excluded from prescription file reads | PARTIAL | Role-state integration matrix (approved/revoked/provider/doctor A/B) across all routes, tampered ownership and provider onboarding |
| H4 Reset/OTP enumeration and brute force | Existing generic forgot-password response and auth throttles described in review; no new OTP code in this patch | UNVERIFIED | Test OTP expiration, replay, per-account throttles across workers, password recovery mail token and session revocation |
| H5 Stored XSS | Existing escaped doctor/pharmacy descriptions per assessment | PARTIAL | Test all rendered admin/public fields using synthetic payloads in browser |
| H6 Zoom/Agora token ownership | Existing booked-appointment and scoped Zoom checks per assessment | PARTIAL | Staging two-patient, two-clinician calls, booking cancellation, token expiry, log/push privacy |
| H7 Incomplete assessment finding | No sufficient original details | UNKNOWN | Obtain complete assessor finding and reproduce before clearance |
| M1 IDOR: address/booking/clinical records | PR #32 adds active clinician gate to appointment details; notes already scoped to assigned approved doctor | PARTIAL | Exhaustive owner/other-user tests for delete, review, consultation, appointments, lab, medicine and payments |
| M2 Public prescriptions, lab PDFs, patient report images | Authenticated private prescription read, default-off private new write, migration inventory; this continuation adds **staging-only non-destructive copy + CAS database update + hash/rollback ledger** | **OPEN HARD BLOCKER** | Full document categories; verified production migration, working Patient/Plus Bearer downloads, legacy anonymous URLs 403/404, CDN/cache purge, historical consent/retention approval |
| M3 Agora channel hijack | Bounded token policy and booking check already exist | PARTIAL | Independent endpoint penetration test and joined-session revocation |
| M4 Rate limits / login / OTP | Current auth throttle and OTP checks exist | PARTIAL | Runtime multiworker and device tests with fixed client behavior |
| M5 Unsafe mutation GET / duplicate routes | Legacy GET delete-account retained for installed clients | OPEN | Migrate both apps to POST, verify role sessions, then remove GET safely |
| M6 Language upload traversal | Existing constrained uploader source | PARTIAL | Malicious filename, MIME, symlink and Unicode adversarial tests |
| Payment replay / forgery / incorrect totals | Gateway verification previously added; new check rejects unbound externally supplied Stripe ch_ references; logs fixed | **PARTIAL / RELEASE BLOCK** | Receipt reservation ledger spanning appointment/medicine/lab, refunds and duplicate concurrency, Paystack/Flutterwave/PayPal/Razorpay owner binding, server-calculated prices, 3DS/gateway live sandbox testing |
| Workforce recruiting vs clinical data | PR #31 uses separate tables, non-Passport tokens and default OFF gate | PARTIAL / RELEASE BLOCK | Synthetic account/employer verification, licence expiry, recruiter legal/consent approvals, do not enable pilot |
| Third party AI, Maps, notifications | Server-side Places, default-off nonclinical AI and controls present in earlier PR | PARTIAL | Restricted API keys, billing quotas, PHI redaction, privacy/consent review and per-route 401/429/503 |

## Changes made in this continuation (draft PR #32)

- `DoctorApiController::apiAddPrescription`: reject suspended prescriber, validate medicine list JSON and item shape, limit to 50 entries, derive patient identity from assigned appointment rather than trusting supplied user_id. Existing uploader remains feature OFF by default. Route-level Bearer auth stays unchanged.
- `DoctorApiController::apiSingleAppointment`: require currently approved and active assigned doctor/provider profile; a revoked account cannot continue viewing assigned appointment details merely because its user still has a token.
- `PatientMedicalFileController`: attached approved and active medical doctor (or owning patient) only; explicitly labeled provider profiles cannot open prescription PDFs; no public fallback.
- `PaymentGatewayService`: externally supplied Stripe `ch_` charge now must carry authenticated `user_id` metadata matching the current caller. **Old Stripe charges without metadata are deliberately rejected** and require client/web staging acceptance; no claim that all gateways or cross-product claims are safe.
- `PaymentApiController`: replace raw Stripe exception logging with fixed nonsensitive event.
- `DoctorApiController::apiPurchaseSubscription`: unpaid or tokenless requests now remain inactive and do not displace a previously paid subscription; replacement occurs transactionally only when payment is verified.
- `DoctorApiController::apiStatusChange`: require current approval/active state, lock the appointment and create at most one settlement on concurrent close requests; push transport errors are logged without exception detail.
- Doctor Agora and outbound chat-push endpoints now check active, approved status before issuing new tokens or notifications.
- `StageLegacyPrescriptions` + migration ledger: opt-in synthetic-only staging copy preserving public source; SHA256 digest, compare-and-swap DB update and rollback to original if its digest still matches. No source/private file deletion and no production execution. Writes additionally reject any database not clearly named `synthetic` (except isolated SQLite `:memory:` fixtures).
- Unit/source and synthetic feature regression test fixtures updated for provider and revocation cases.

## Current tested evidence and limitations

The previous PR #32 description reports source-only checks (14/14) at an earlier head. Those results do **not** validate this changed head. Private GitHub Actions run for this continuation has been created but did not provide usable step results; verify the latest run and gating details before claiming syntax/feature tests PASS. An earlier public-builder backend QA attempt was blocked by missing `CARE360_BACKEND_SOURCE_READ_TOKEN`. The absence of logs or statuses is not evidence of correctness.

Required: PHP 8.3/8.4 syntax check of **every changed PHP file**, Laravel/PHPUnit `LegacyPrescriptionStagingTest`, `PrivatePrescriptionAccessTest`, `PrivatePrescriptionWriteTest`, finance/OTP suite, MySQL staging migrations and test fixtures, and Auth/role/API acceptance. Offline contract checks are not substitutes for endpoint tests.

## Operational separation and rollback

**No live medical data is authorized to be copied, edited or logged.** Migration flags default OFF. For isolated synthetic staging, create a verifiable backup and ensure both Flutter clients support protected downloads before toggling private writes. On application fault disable new private writes and test per-document retrieval (private records always return protected routes); never presume flag reversal restores old URLs for migrated references. Use command rollback with synthetic confirmation only when public originals still match the ledger; never delete the ledger containing active mappings. A real production plan must be separately reviewed, deployed by an authorized operator, and tested for no direct old URL access after server/CDN denial.

## GO / NO-GO

**NO-GO.** Do not merge stacked PRs, turn on private writes/Workforce, deploy Namecheap, seed real users, or export a Play handover as release-certified. Security clearance requires H1/H2 evidence, M2 migration/old URL cutoff, payment reconciliation, client builds and independent staging retest signed by clinical/security/release owners.

## Additional security execution — clinical and payment boundaries (8 Nov 2026)

**New committed source after the initial matrix:**
- Patient and Doctor completion now serialize on the same appointment row with a database transaction and `lockForUpdate()`; both use `Settle::firstOrCreate` and preserve the rule that completion alone cannot set paid. Notification service errors no longer rollback committed clinical state.
- Patient booking explicitly rejects `status != 1` **or** `is_approved != 1` for doctor/provider profiles. This is a deliberate approval-state behavior change for pending clinicians.
- `EnsureApprovedProfessional` middleware requires a current active, approved professional and a matching doctor/provider role. Applied to 20 sensitive professional route entries: clinical appointment/notes/prescribing, history, status mutation, teleconsultation initiation and finance/payout/notifications. Registration and verification profile updates remain usable. Approved legacy doctor rows with NULL `user_role` require current explicit doctor account role.
- `PaymentGatewayService` checks previously-used receipt references in four current tables (appointment, purchase_medicine, report, doctor_subscription). **Sequential** cross-service replay is rejected, but this does not solve simultaneous attempts without an atomic unique receipt ledger.
- Paystack verification now checks the authenticated `metadata.user_id`. Doctor/Plus Paystack initialization derives server user/email/currency, sends 100x minor units and stamps server-side `metadata.user_id`. The client `email` field is ignored for payer identity. All Paystack flows must be retested for two-decimal currency support and missing legacy metadata.
- PayPal Orders v2 verification requires every purchase-unit `custom_id` to match the authenticated account, alongside completed captures, correct currency and amount. **A client-authored `custom_id` is not sufficient proof of payment ownership**: server-created checkout ownership and a persistent unique payment-claim ledger remain mandatory before financial acceptance. Legacy unbound Orders v2 must fail closed.
- Removed raw SMTP and OneSignal exception messages from Patient booking notification logs.
- Added synthetic payment gateway harness cases for correct/incorrect Paystack and PayPal identity, and cross-product reuse; synthetic clinician guard test and offline structural checks added to the draft workflow.

**CI blocker remains:** private repository Actions jobs are completing failed with no recorded steps. The connected GitHub interface exposes no usable logs for those jobs, so the cause is **not conclusively identified**. Prior independent public-builder execution was blocked by missing `CARE360_BACKEND_SOURCE_READ_TOKEN`; only a suitably authorized owner can provision the read-only secret. No PHP syntax/Composer/PHPUnit/MySQL result can be claimed from these runs.

**Release effect:** #22 public medical documents, #23 Namecheap/credential rotation and #29 integrated security acceptance remain OPEN. Do not merge, migrate real records or ship either application until all medical, payment, OTP and device checks pass in controlled staging.
