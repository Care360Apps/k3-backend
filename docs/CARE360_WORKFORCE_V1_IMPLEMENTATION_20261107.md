# Care360 Workforce — isolated Student/Graduate & Employer Pilot Implementation

**Date:** 7 November 2026  
**Status:** Draft code under feature flag, **NOT enabled in production**, not a completed employment marketplace.  
**Dependents:** Patient Workforce branch `feat/patient-workforce-v1-isolated-20261107` on Patient PR #35; backend branch `feat/backend-workforce-v1-isolated-20261107` on secured/combined backend PR #28. Plus doctor/provider clinical architecture remains unchanged.

## Implemented now

### Patient
- A **Workforce** service card on Patient Home plus a discoverable navigation-drawer entry.
- Native mobile landing: **Join Workforce** (student, graduate or licensed professional) and **Hire Workforce** (hospital or healthcare employer).
- Clear verification steps and disclosure that care/patient/MindCheck records do not enter recruitment.
- Build flag `--dart-define=K3_WORKFORCE_PILOT=true` is required before attempting applications. The app also checks `GET /api/workforce/v1/status`; if the server says no, all sign-up doors stay closed with an explanation. Default app setting is OFF.
- Isolated candidate/employer registration, login with secure local Workforce token, own profile, work availability and employer search UI.
- No reuse of clinical `Preferences.auth_token` or Patient Doctor chat/Firestore. No live candidate directory in an anonymous session.
- Only the 11-server-approved occupation codes may be selected. Regulated professionals must have independently verified, unexpired licences.
- Candidate can consent to publishing and toggle availability, but verification remains pending until reviewed. Employer can provide organization identifiers, but cannot search until approved.

### Laravel
- Feature flag `K3_WORKFORCE_PILOT_ENABLED=false` in `config/workforce.php`, no production enablement.
- New separate `workforce_accounts`, `workforce_sessions`, `workforce_candidates`, `workforce_employers` tables. No relationship to patient medical records or existing Passport users.
- `AuthenticateWorkforce` accepts only independent high-entropy opaque session tokens. Only SHA256 token hashes are stored; sessions expire in 12 hours and logout revokes them. Existing `auth:api` clinical resources are not exposed by this credential.
- Registration requires adult attestation, strong password and agreement; backend endpoints are rate-limited. No identity or professional eligibility is inferred from registration.
- Patient/organization profile writes whitelist user-editable fields. Material changes automatically return verification status to **pending**, invalidating previous education/licence or employer approval.
- Candidate discovery is available ONLY to approved employers with confirmed email status, and ONLY shows independently verified candidates who explicitly opt in and remain available.
- Approval of regulated roles requires server-side licence verification and expiry; applicant-selected labels never suffice.
- `workforce_verification_events` stores the review decision and SHA256 fingerprints of separately held evidence. A local/staging-only `php artisan workforce:review` command requires an authorized reviewer, evidence digest, independently confirmed email, explicit `--confirm` and, for regulated roles, current licence evidence and expiry.
- Separate `workforce_invitations`, `workforce_messages` and report tables are **schema only** for future permissioned recruiter chat. **No public invitation/chat APIs exist yet.** Do not re-use patient clinical Firestore messages or imply that recruitment chat works.

## API contract
| Method | Path | Access |
| --- | --- | --- |
| GET | `/api/workforce/v1/status` | Anonymous; publishes only feature state, no candidate identities |
| POST | `/api/workforce/v1/register` | Pilot-only, throttled; candidate or employer |
| POST | `/api/workforce/v1/login` | Pilot-only, throttled; returns separate opaque session |
| GET | `/api/workforce/v1/me` | Workforce token; only self |
| POST | `/api/workforce/v1/candidate/profile` | Candidate token; owned fields only |
| POST | `/api/workforce/v1/employer/profile` | Employer token; owned fields only |
| GET | `/api/workforce/v1/candidates` | Independently approved employer only; consented and valid candidates |
| POST | `/api/workforce/v1/logout` | Workforce token; revoke current session |

**Important:** Requiring `workforce_accounts` rather than adding a naive `student` / `employer` role to the existing Patient Passport `users` model is intentional. The existing `auth:api` group includes many clinical routes. Passwordless role linking/SSO may be added only AFTER every clinical endpoint enforces its own correct role and object ownership. Otherwise employer accounts could become clinical accounts.

## What is NOT implemented or verified
- No independent student-school integration, primary-source professional licence API integration, image/document uploads, regulator approval or live school administrator verification portal.
- No customer-facing email verification delivery: in this restricted pilot, email ownership is checked **manually by the authorized local/staging reviewer**, recorded in the review process. Do not open public registration until automated email verification exists.
- No employer invitations, in-app recruitment chat, job posting, interview calendar, offers, payments or paid employer subscription endpoints yet.
- No student or employer production data has been imported. Avoid real identity documents or clinical records in synthetic tests.
- No legal confirmation of private employment agency licensing, mandatory staffing/insurance requirements, job placement commissions or data-controller obligations.
- Backend Laravel PHPUnit integration tests and database migrations **not yet executed** on the connected Namecheap/staging instance. Keep all changes draft.
- This does not close existing Care360 security issue #29 (historical secrets, public clinical files, production debug and full clinical acceptance).

## Local/staging reviewer example — DO NOT use on production
Only after primary source and identity checks, and with the feature enabled in a safe test environment, an authorized operator may run a controlled review:

```sh
php artisan workforce:review candidate 1 verified \
  --reviewer=internal-reviewer-001 \
  --evidence-sha256=<64-hex-character-evidence-digest> \
  --email-checked --confirm
```

For a regulated profession, add **both** `--licence-expires=YYYY-MM-DD` and `--licence-evidence-sha256=<64-character-SHA256>`. This does not bypass the separate obligation to check licence status directly with the relevant Nigerian regulator.

Do not place identity documents in shell arguments, logs or public GitHub. Store the actual evidence in restricted, access-logged storage; only its hash is referenced by the reviewer command.

## Test plan and exit gates
- Static: `php tools/test_workforce_pilot_contract.php`.
- PHPUnit: `php artisan test --filter=WorkforcePilotTest` with a test-only SQLite connection; assert pilot off, email/OTP requirements before publication, role segregation, session revocation, no self-verification, licence controls and approval invalidation.
- Flutter: `flutter test test/care360_workforce_navigation_test.dart`; confirm both join/hire paths, no contact fields exposed when pilot off, 320px width and distinct auth state.
- Device: run Patient in isolated LAN staging with fictional student, graduate and employer records; test registration, credential pending/approved/expired, employer search and logout in BlueStacks.
- Security: confirm Workforce tokens cannot access `/api/appointments`, `/api/medical-files` or Plus doctor routes; employer B cannot inspect employer A; no raw document download, image URLs, medical conditions, phones or email in searches; prevent scraping and brute force.
- Privacy: implement actual Workforce privacy notice, safe data retention and deletion, employment age checks, employer verification and incident reporting before processing real applicants.
- Business: candidate profiles free in pilot; consider employer-paid job listings/subscriptions after Nigerian recruiter licensing and payment compliance review. Keep income separate from SpeakOut NGO programs.
- Rollout: first institutional pilot with participating College of Health and verified hospitals, only after security issue #29 and clinical data migration gates.

**Release decision:** NOT ready for real recruitment. Use synthetic demo data in a test environment only. The visible Patient tile is an informational preview until *both* client and server flags are explicitly enabled.
