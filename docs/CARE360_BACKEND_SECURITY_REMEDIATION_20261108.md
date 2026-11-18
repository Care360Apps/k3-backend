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

## Partial local PHP runtime evidence — verified source blobs

An isolated PHP 8.4 command-line environment syntax-checked **four exact files** from this draft branch. Their local `git hash-object` values matched the GitHub blob SHAs:

| PHP file | Git blob SHA | Local `php -l` |
|---|---|---|
| `app/Http/Middleware/EnsureApprovedProfessional.php` | `603b95d79422c221c85f0c1d4a6856ecd0586ce3` | PASS |
| `tools/test_backend_security_continuation_contract.php` | `ef5dc0d9ef1a84207413e31b8c2190aafbd6b343` | PASS |
| `tools/test_approved_professional_guard_contract.php` | `39602fadb0a90500f17bd4c367872931935c1c5f` | PASS |
| `tools/run_backend_security_checks.php` | `97e9dbf73809e6197d961796908b4384a99666f7` | PASS |

This is genuine syntax execution for those four files only. The other Laravel files and source-contract test bodies have **not** been linted locally as a complete checkout; Composer/Laravel integration, workflow payment fixtures and clinical/MySQL tests remain **UNVERIFIED**. GitHub Actions still reports both jobs failed before recording steps. The tool to run on an authorized complete developer checkout is `php tools/run_backend_security_checks.php` — read-only PHP lint and offline synthetic checks; it deliberately does not run database migrations or PHPUnit.

## Pending clinician Firebase credentials and suspension — continuation on 8 November 2026

The doctor login previously minted a Firebase custom token **before** checking professional approval. Provider registration/login likewise issued tokens to applicants whose credentials had not yet been approved. Firebase chat/backend rules could treat these tokens as authenticated professional identities even while the Laravel clinical routes were guarded.

This draft branch now:
- Sets `doctor.is_approved=0` explicitly at **provider registration** and returns `approval_status=pending` without a Firebase custom token.
- Permits pending provider/doctor applicants to authenticate with Passport for onboarding/profile completion, while **only active, approved, correctly typed professional profiles** receive Firebase custom login tokens. Mismatched or absent Doctor/Provider profiles fail login.
- Rejects users with `users.status != 1` at doctor/provider login and on every `EnsureApprovedProfessional`-guarded request, including requests using previously issued Passport access tokens.
- Refuses the assigned clinician's private prescription download if their account becomes suspended; patients retain their own medical-document access when their clinician is suspended.
- Adds source-only contract `tools/test_professional_firebase_gate_contract.php` to offline QA/CI, and synthetic SQLite feature tests for suspended professional guard and private medical downloads.

**Important limitation:** Withholding new Firebase custom tokens does **not revoke Firebase sessions already established**. Firebase custom tokens can be exchanged for longer-lived sessions; a one-hour custom token expiry does not itself revoke those sessions. A security owner must inspect the actual Firebase rules for doctor/provider chat access, revoke affected Firebase refresh tokens and invalidate sessions/roles on suspension as appropriate. This is not implemented/tested here because the Firebase projects/rules are outside this backend branch. Do not infer private chat revocation from Laravel authorization alone.

**Verification:** Source committed to draft PR #32. No authenticated Firebase sandbox sign-in, rules validation, Composer, or Laravel/PHPUnit execution is claimed. Private Actions jobs have ended failed before any steps were recorded; investigate org Actions billing, access, or runner policy from the owning account rather than guessing which one is responsible. The source-only test does not replace these integration checks.

## Atomic payment receipt claims — disabled-by-default security candidate (8 Nov 2026)

**Identified failure mode:** The existing gateway verification and cross-table search stop **sequential** receipt replays but not two near-simultaneous order requests that each search before either creates its record. A receipt can potentially be reused to pay for more than one Care360 service. A unique database constraint is required; application-only preflight logic is insufficient.

**Implemented exclusively in draft PR #32:**
- `database/migrations/2026_11_08_070000_create_payment_receipt_claims.php` introduces the `payment_receipt_claims` ledger with a **unique SHA-256 hash of each verified gateway reference**, user ID, gateway and order binding. No raw payment token is stored in this ledger. Schema rollback refuses to erase a nonempty ledger.
- `PaymentReceiptClaimService::claim()` rejects missing/invalid paid references. When `K3_ATOMIC_PAYMENT_RECEIPTS=true`, the database's unique index rejects duplicate claims, and a missing/unavailable ledger fails closed with a generic `422` validation error; neither raw transaction IDs nor query bindings are logged.
- All four paid product creation paths — **appointment**, **laboratory booking**, **pharmacy order** and **doctor subscription** — create the order and claim in **one DB transaction**. In the pharmacy path the stock/settlement transaction includes the claim. In subscription replacement the old plan's status change rolls back if the new receipt claim fails.
- `config/payment_security.php` defaults the gate **OFF** until migration, legacy-receipt reconciliation and MySQL integration testing are approved.
- New PHPUnit synthetic SQLite tests: same receipt for two products, successful distinct receipts, opt-out/unpaid, missing ledger and rollback after simulated failed order; plus `tools/test_atomic_payment_receipts_contract.php` in portable QA and Actions.

**Verified locally in an isolated PHP 8.4 CLI:** The exact GitHub blobs for the receipt service (`b873ae596b175fd7644748df8efcb9bac00a1225`), config (`cf1b020f453e6d3a6e3c41610a94b5d776c0899d`) and migration (`af31e61a449a4cb6e1399a5377bc744503bc48bb`) passed `php -l` and had matching `git hash-object` checks. The **actual service** was executed with isolated fake Laravel facades and an in-memory unique-receipt simulator: **7/7 scenarios passed** (insert, hashed reference, replay, missing ledger, unpaid, disabled, invalid provider). This does **not** establish real MySQL transactional consistency, Laravel/PHPUnit acceptance, or external gateway reconciliation.

**Release blockers before enabling in real operations:**
1. Reconcile and backfill historical **verified** receipts into the ledger, resolving duplicate historical claims first. A production backfill command has **not been built or executed**. Never import arbitrary client-stamped `payment_status=1` as proof of payment.
2. Run the migration and the synthetic PHPUnit suite against a copy of a known schema in segregated staging. Test two parallel authenticated requests with the same gateway reference under MySQL and verify **one** paid order, **one** claim and **one** stock/settlement effect.
3. Test payment reversal, refund, repeat webhook, network timeout and retry/idempotency handling. Unique receipt claims prevent double-credit, but direct Stripe token charges may still produce repeated **real charges** if a client retries before claim handling; safe charge-intent idempotency and reconciliation are not implemented.
4. Eliminate remaining user-ownership gaps for Flutterwave and Razorpay. PayPal Orders v2 still needs server-initiated checkout/ownership proof. Gateway credentials, domain TLS and callback signatures require security review.
5. Obtain release/security owner sign-off before turning `K3_ATOMIC_PAYMENT_RECEIPTS=true` on. Turning it back off reopens the older concurrency weakness; once live, treat the gate as sticky and do not drop an occupied ledger.

**NO-GO:** This work is a staged risk reduction, not production payment certification, refund protection, chargeback correctness or a guarantee that every payment path calls the ledger.

## Web subscription payment integrity — legacy GET and hosted checkout (8 Nov 2026)
A further critical route review found **two separate authorization bypasses** in doctor web payments:
1. The existing authenticated `GET /subscription/changePaymentStatus/{id}` called `SuperAdmin/SubscriptionController::changePaymentStatus` and unconditionally set `doctor_subscription.payment_status=1` for **any supplied subscription ID**, without receipt, payment provider verification or ownership. It now returns HTTP **410** and performs no writes. This intentionally retires an unsafe legacy endpoint rather than perpetuating a forged-payment path.
2. Web `DoctorSubscriptionController::purchase()` set `status=1` and deactivated existing paid plans **even when the new order was unpaid**. It now uses server-priced plans, keeps unpaid attempts inactive and preserves a previously paid subscription. Paid activation and the receipt claim occur in one transaction with a doctor-level row lock shared with the Plus API.

A web Stripe `/stripePayment` charge did not contain `metadata.user_id`, but later verification requires it. New web charges and service-created Stripe card-token charges now include account-owned metadata. Older unbound charges cannot be automatically reconciled and remain subject to gateway/support verification.

**Flutterwave hosted web subscriptions:** The old HTML form sent a non-unique plan ID as `tx_ref`; the verify callback merely checked amount/status and could treat another receipt as belonging to this subscription. It now uses `SubscriptionCheckoutReference::forSubscription` to derive an HMAC of the server-side subscription ID, logged-in owner and authoritative price, using `APP_KEY`. The provider-verified response must echo the exact expected reference **and** transaction ID before a paid subscription can become active. A second callback cannot create another active update. All web callback updates/receipt claims are performed in a database transaction.

**Test evidence:** 14/14 targeted repository-source assertions PASS; the **exact GitHub blobs** for `SubscriptionCheckoutReference.php` (`b4427b367f24eb3daffae3fa7a84c0736c3a1280`) and `tools/test_subscription_checkout_binding.php` (`6adb872f7b549a559aa7f9a583e013a9146d37e8`) were independently PHP 8.4 syntax checked and the latter executed: **6/6 standalone synthetic HMAC scenarios PASS**. PHP/Laravel end-to-end and actual gateway tests remain unverified, and private GitHub Actions have no recorded runner steps.

**Compatibility / rollback:** Old in-flight hosted checkout references cannot pass the new HMAC check; gateway-settled historical transactions require **manual, independently verified reconciliation**, not a bypass. Rotating `APP_KEY` also invalidates pending signed checkout references: coordinate rotation with pending transaction handling. Old public Flutterwave/generic payment flows outside web doctor subscriptions and Razorpay/PayPal Orders v2 still require secure ownership review. Do not remove the 410 guard or activate `K3_ATOMIC_PAYMENT_RECEIPTS` before MySQL/sandbox acceptance. No live changes made.

## Suspended clinical access and strict private-PDF provenance — 8 November 2026

**Clinical status gap:** The shared `DoctorApiController::apiSingleAppointment` endpoint checks doctor-profile approval and status but is registered without the `EnsureApprovedProfessional` middleware; it previously omitted the underlying `users.status` suspension bit, so a suspended clinician could still fetch an assigned patient's appointment details with an unexpired token. That endpoint now fails closed (404) when the authenticated professional user is suspended. `UserApiController::apiBooking` now also verifies the professional account's `users.status=1`, preventing new bookings for suspended doctors/providers even if their `doctor.status/is_approved` fields remain positive.

**Private file provenance gap:** The authenticated prescription download previously accepted any 16–80-character safe-looking `.pdf` basename located within the private folder. It now calls `PrivatePrescriptionStorage::isPrivateFilename()`, which requires the actual `k3priv_` + 40 lower-case hexadecimal filename pattern generated by the private writer; it still verifies the file is confined to private storage with no file symlink. New synthetic test `test_arbitrary_pdf_inside_private_directory_is_not_downloadable` creates a lookalike PDF inside that directory and expects HTTP 404. The existing test still requires that patient access remains available when an assigned clinician has been suspended.

**Verification limitations:** 8/8 targeted JS-driven source-structure assertions passed after re-fetching GitHub file contents. The **exact committed** PHP controller blob `ab0a597456c9f42007a376e7b6f8910409a45590` was reconstructed locally and independently verified by `git hash-object`, then passed PHP 8.4 syntax lint. A new source contract was wired into `.github/workflows/quality.yml` and `tools/run_backend_security_checks.php`, but those repository-wide checks/feature tests could not yet execute because GitHub-hosted jobs remain blocked before runner allocation. All protections are draft changes only; none are live until staged, reviewed and merged.

**CI diagnostic:** Actions run `42470968455` and its one failed-jobs-only rerun both had **0 ms total billed job time**, zero runner allocation and no job steps. The actual cause is still unconfirmed (see `docs/CARE360_BACKEND_CI_BLOCKER_AND_LOCAL_QA_20261108.md` for nonproduction workstation QA and owner-side GitHub settings checklist). No organization billing or Actions setting was modified.
