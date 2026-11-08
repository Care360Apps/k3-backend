# Care360 Backend — Patient/Plus/Workforce API and migration handover

**DRAFT contract for security candidate:** PR #32 → #31 → #28 → main; not a deployed API promise.  
**Boundary:** Work here changes only Care360Apps/k3-backend. Client application repositories are unchanged.

## Shared conventions

- HTTPS only in staging/production. Send `Authorization: Bearer <clinical Passport token>` on clinical `auth:api` endpoints. Never place tokens in the URL, screenshots, analytics or notification payloads.
- Unauthorized clinical requests: `401`; unknown/unowned medical resources: `404` to avoid ID probing; unacceptable input: `422`; not-approved clinical prescriber: `403`. Exact client error parsing must be tested under Laravel staging.
- `Cache-Control: private, no-store` for medical PDF delivery; client must read bytes in authenticated memory and offer patient-controlled save, not launch an anonymous browser URL.
- The recruitment API uses **independent Workforce bearer tokens** accepted only by `/api/workforce/v1/*`, not Passport's clinical auth guard. No Workforce account automatically becomes a Patient, licensed Doctor or Provider.

## Patient ↔ Laravel

| Endpoint | Method | Relevant request | Response and access |
|---|---|---|---|
| `/api/login`, `/api/check_otp`, `/api/forgot_password` | POST | Existing auth payloads | Rate-limited auth flow; OTP and account recovery still require runtime security QA |
| `/api/book_appointment` | POST | Server-priced booking payload | `auth:api`; verify ownership and gateway status before marking paid |
| `/api/appointments` | GET | Bearer token | Patient-scoped appointment list; integration QA needed |
| `/api/appointment_details/{id}` | GET | Appointment ID | Patient owner or active approved assigned professional only |
| `/api/prescription/{appointment_id}` | GET | Appointment ID | Returns `success`, `data` with `prescription.pdfPath`; private docs expose authenticated endpoint and hide raw PDF name; legacy path can remain public |
| `/api/medical-files/appointments/{appointmentId}/prescription` | GET | Bearer token | `application/pdf`; Patient owner or active approved assigned medical doctor; `404` on mismatch or missing file, no public fallback |
| `/api/book_medicine`, `/api/lab_book` | POST | Existing order/booking payloads | Financial/ownership tests remain required |
| `/api/places/autocomplete`, `/api/places/details` | POST | Search/details | Server-only Google key; customer Bearer never forwarded to Google |
| `/api/generateAgoraToken` | POST | Consultation target | Token limited to booked virtual consultation; staging call authorization still required |
| `/api/delete-account` | POST | Bearer | Preferred; legacy GET still present and must be retired after installed-client migration |

## Doctor/Plus ↔ Laravel

| Endpoint | Method | Relevant request | Response and access |
|---|---|---|---|
| `/api/doctor_login`, `/api/provider_login` | POST | Existing login payload | Rate-limited; approval state must be checked on privileged operations |
| `/api/add_prescription` | POST multipart | `appointment_id` required; `medicines` JSON array of 1–50 items; optional `pdf` ≤10 MB; optional `user_id` ignored for ownership | Approved **active doctor** assigned appointment only, never provider; returns `{success:true,data:<URL>,msg:...}`; invalid schema `422` |
| `/api/doctor_notes` | GET/POST | Appointment ID, note fields | Approved assigned doctor; notes are private and audit retained |
| `/api/doctor_notes/{id}` | PUT/DELETE | Note update / deletion | Final notes immutable; deletion returns 405 |
| `/api/doctor_appointment`, `/api/appointment_details/{id}` | GET | Bearer/ID | Recheck approved/active assignment |
| `/api/generateDoctorAgoraToken` | POST | Booked target | Verify privilege TTL/channel/cancellation in staging |
| `/api/professional/presence` | GET/POST | Opt-in location and shift | Separate Nearby Care policy, rate-limited |
| `/api/stripe/payment_intent` | POST | Existing amount | Intent generated with authenticated user metadata; **not yet final order-server pricing** |

**Subscription and care-status safety change:** unpaid subscription purchases no longer activate a new plan or cancel an existing paid plan. Professional appointment transitions use row locking and prevent duplicate settlement creation. Re-test doctor subscription purchase, commission and notification behavior with synthetic payment outcomes.

**Breaking staging-only payment safety change:** A supplied Stripe `ch_...` reference must have `metadata.user_id` equal to the authenticated account. Legacy web charges without that binding now fail verification rather than authorizing another user's payment. Publisher/payment integrator must resolve this before release; do not bypass.

## Workforce ↔ Laravel (feature OFF)

- `GET /api/workforce/v1/status` exposes availability, currently disabled.
- `POST /api/workforce/v1/register`, `POST /api/workforce/v1/login` accept independent Workforce credentials; active when `K3_WORKFORCE_PILOT_ENABLED` enabled on approved synthetic staging.
- Authenticated Workforce routes: `GET me`, `POST candidate/profile`, `POST employer/profile`, `GET candidates`, `POST logout`. Listings require separately approved, verified consenting candidates AND verified employers. Approval cannot be self-granted.
- No live school portal verification, invitations/chat API, employer job postings, payroll or contact disclosure is claimed complete.

## Staging migration instructions (synthetic records ONLY)

1. Pin exact PR #32 head SHA and review deployment diff; separately confirm real Laravel private directory is physically outside webroot. Back up **synthetic-only** SQLite staging DB and synthetic source fixtures, and confirm no real patient data exists.
2. In a local/test/staging Laravel sandbox, apply migration `2026_11_08_060000_create_medical_prescription_migration_ledger.php`. Production execution of the staging command is prohibited.
3. Run `php artisan medical-files:inventory-safe` (read-only aggregate counts). Inventory includes prescription, pharmacy, laboratory and appointment report-image references; the staging copier currently handles **prescription.pdf only**.
4. Preview `php artisan medical-files:stage-prescriptions --limit=50`. Default dry-run is read-only. Set `K3_SYNTHETIC_MEDICAL_MIGRATION=true` only on a verified synthetic staging environment, never production. The staging database name must contain `synthetic` (or be SQLite `:memory:`), otherwise the command rejects all `--apply` operations. Apply with `php artisan medical-files:stage-prescriptions --apply --confirm-synthetic-staging --limit=50`.
5. Test original hash, new protected bytes, Patient A success, Patient B 404, assigned active doctor success, revoked doctor 404 and provider 404. Confirm the old public file still exists, and document that **its direct anonymous URL is still exposed**; do not tell patients files are private until web server denial/retention decision.
6. Synthetic-only rollback: `php artisan medical-files:stage-prescriptions --apply --rollback --confirm-synthetic-staging --limit=50`. Uses ledger hash checks and compare-and-swap to restore existing public references **without deleting private copies**. Rollback is *not* permitted as a production privacy bypass.
7. For production, design a separate staged operational migration for all document classes, backups, installed client compatibility, temporary read-through/dual-read if legally approved, webserver deny rules and CDN purge; rehearse rollback on redacted/synthetic copies. Owner must approve separately. **No production migration procedure has been executed.**

## Exact acceptance / developer handover

- Laravel syntax/Composer/PHPUnit and MySQL integration suite pass on pinned source with job logs; active/disabled Provider and Doctor role suites; prescription ownership and revocation; two-patient file denial; streamed PDF headers, cache policy and missing-file 404.
- Independent gateway sandbox test: underpayment, forged token, cross-user charge, cross-product replay, duplicate simultaneous confirmation, refund and callback replay; all financial state updates atomic and ownership-bound. Blocking issue if unresolved.
- Verify pharmacy inventory CSV preview and adjustable commission, hospital/lab documents, consultation notes, Agora, notifications and Places using fully fictional test identities.
- Confirm #22 public-file shutdown with anonymous HTTP HEAD/GET checks and retained-backup approvals; confirm #23 Namecheap server security and credential rotation by authorized operator. Mobile teams review this **contract only**, not source changes to their repos.
- Handover bundle must pin all three repo SHAs, document schema/flags/client minimum versions, include no credentials/medical records, and contain a written reversible procedure and clinician/security acceptance before Play publisher receives a release candidate.

## Security-candidate changes requiring Patient/Plus client acceptance

1. **Professional approval.** `POST /api/book_appointment` now returns `422` for unapproved or inactive clinicians. Twenty professional clinical/financial route entries return `403` with `code=PROFESSIONAL_NOT_APPROVED` or `PROFESSIONAL_ACCESS_DENIED` after revocation or role mismatch; onboarding and self-profile update routes are separate. Both clients must handle pending/denied states without retry loops.
2. **Appointment completion.** `POST /api/complete_appointment` (Patient) and `POST /api/status_change` (Plus) now coordinate through row locks. Repeated or already-closed transitions fail rather than generate duplicate settlements. Ensure the UI handles `422` gracefully and does not interpret a successfully completed consultation as payment confirmation.
3. **Paystack initiation.** `POST /api/paystack_init` on the professional API now uses the authenticated account's email, the server's configured currency and minor-unit amount (`round(amount * 100)`), attaching server-owned `metadata.user_id`. The client-supplied email is not payer authority; the supplied currency must equal server currency. `PaymentGatewayService::verifyPaystack` rejects references without matching verified gateway metadata. Legacy payment references can fail until client and gateway rollout is coordinated.
4. **PayPal Orders v2.** Gateway capture must be `COMPLETED` and every purchase unit have server-stamped `custom_id == authenticated user id`. Order ownership should be created/recorded by a trusted Care360 backend checkout, not client JavaScript; currently that server-side v2 checkout/claim ledger is **not implemented**. Do not treat source matching alone as payment authorization.
5. **Cross-service receipt replay.** Backend checks appointment, medicine purchase, lab report and doctor subscription references before accepting a reused gateway receipt. A shared atomic unique receipt ledger and gateway refund/reconciliation checks are still required to defeat simultaneous claims.
6. **Sensitive booking logs.** Raw external mail and push exception text has been replaced with fixed warning labels; no patient payloads or credentials should reach logs.

**Testing:** corresponding synthetic feature and offline source checks are committed, but real PHP/Composer/Laravel integration tests have not run successfully because private Actions jobs fail before recording steps. The code changes above are a draft contract, not approved live behavior.

## Portable offline PHP checks for the publishing developer

On a clean, authorized checkout of the pinned backend candidate, run `php tools/run_backend_security_checks.php` with PHP 8.3+ from the repository root. It syntax-checks PHP source and executes standalone regression scripts without production database credentials, Laravel migrations or external gateway calls. Save redacted command output and exact commit SHA for handover.

**Do not label this output a Laravel end-to-end test.** For full acceptance, Composer dependencies must be installed in an isolated synthetic staging environment and PHPUnit, database migrations and authenticated medical/financial API tests must be run separately. A private Actions run failing with no steps is not evidence of source failure or success. Never point `php artisan test` or any migration command at Namecheap production.

## Atomic receipt ledger API behavior (draft only)

When a successful gateway verification creates a Care360 appointment, laboratory order, medicine order, or doctor subscription, the backend now has an **opt-in** unique receipt claim. The **request/response field names remain unchanged**, but with `K3_ATOMIC_PAYMENT_RECEIPTS=true`, a reused verified receipt returns an ordinary Laravel `422` error under `payment_token` and the new order transaction is rolled back. The new ledger migration must be applied before turning on the gate; a missing ledger also rejects paid orders. Unpaid/COD bookings do not create ledger rows.

**Flutter Patient / Plus handling:** Do not treat a duplicate-charge validation response as permission to charge the customer again automatically. Show a reconciliation/retry-safe status and let customer support locate the original transaction by a trusted gateway reference. The backend does **not** currently expose a safe idempotent reconciliation endpoint or guarantee no repeated external charges. Installed clients must be tested with the sandbox before feature activation.

**Developer controls:** `K3_ATOMIC_PAYMENT_RECEIPTS=false` by default, and `payment_receipt_claims` migration `2026_11_08_070000_create_payment_receipt_claims.php`. Before enabling, review past payment records against provider receipts and backfill the ledger with independently verified history. Test two concurrent requests bearing the same payment reference under MySQL. The ledger's unique `receipt_sha256` column is the concurrency guarantee, not the earlier PHP preflight search. **Do not backfill from untrusted historical paid status flags alone.**

The migration is additive and reversible only when its ledger is empty. Once claims exist, the ledger must be retained for audit/replay defense. No production backfill, migration or feature activation has been performed.

## Doctor web subscription and Flutterwave handover (draft)
- `GET /subscription/changePaymentStatus/{id}` is **retired** and deliberately responds `410 PAYMENT_VERIFICATION_REQUIRED`. It previously accepted unverified paid-state changes. Do not use it for Flutterwave, Stripe, API clients or administrative shortcuts.
- `POST /purchase` (authenticated doctor web) now keeps unpaid records `status=0`; an unpaid attempt cannot deactivate the existing paid plan. Verified purchases lock the doctor row and create a unique receipt claim in the order's DB transaction when the atomic gate is on.
- `POST /stripePayment` stamps server-derived `metadata.user_id` on Stripe Charges, and service-created card-token charges do likewise. Old `ch_...` charges without user metadata will be rejected by current verification; operations must reconcile them with the payment provider rather than bypass the check.
- `GET /subscription_flutter/{id}` now renders a server-derived HMAC `tx_ref` for that pending subscription, authenticated user and price. `GET /subscription_flutter_verify/{id}?transaction_id=...` verifies Flutterwave status, currency, amount, signed `tx_ref` and exact gateway transaction ID; only then does it activate the subscription and claim the receipt transactionally.
- **Release QA required:** Test pending checkout creation, callback on paid/underpaid/cancelled transactions, two doctors' cross-subscription references, duplicate callback, invalid HMAC, stale `APP_KEY`, inactive plan preservation, and existing Flutterwave/Stripe form compatibility in synthetic staging. HMAC test alone does not establish live payment safety. Plan for old pending sessions whose former `tx_ref` format is no longer supported.

The new signed subscription reference requires a stable configured `APP_KEY`; key rotation invalidates existing in-flight checkout references. The generic Flutterwave and Razorpay payment paths outside doctor subscriptions still lack verified server-owned checkout bindings. **No production payment rollout is approved.**
