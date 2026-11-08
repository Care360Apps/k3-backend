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
