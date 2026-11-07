# Care360 security and responsiveness gate — 7 November 2026

**CONFIDENTIAL — Authorized Care360 engineering and publishing team only.**
Scope: Care360 Laravel backend + Care360 Patient + Care360 Plus. This is a
developer handoff, **not** a penetration-test certificate or production
attestation. Work must be completed with synthetic staging data.

Source baseline: *Care360 Plus — Security Assessment*, dated 9 October 2026,
which used static analysis of the outgoing vendor's handover archive, not live
tests. The report header says **3 critical, 7 high, 6 medium, 5 low**; however,
only H1–H6 are explicitly itemized on the supplied pages. Obtain any omitted
H7 finding from the original assessor. Do not claim all 21 findings closed.

## Critical findings

| ID | Original finding | 7 Nov source review | Required release evidence |
|---|---|---|---|
| C1 | Client-trusted payments/amounts | `PaymentGatewayService::resolve()` refuses client-paid status without gateway confirmation; `MedicineOrderPricing` calculates server-side medicine totals. **Additional bypass found** in Patient and Doctor completion methods setting `payment_status=1`. This PR removes that mutation and bases settlement `payment` on verified payment status. | Run payment regression and integration tests; deploy backend; confirm configured gateway secrets, currency/amount/reference/owner, rejection and duplicate prevention; verify COD never becomes paid by completing care |
| C2 | OTP-based takeover | `OtpService` hashes 6-digit code, 10-min expiry, 5 attempts and resend limits; verification only for unverified users. Auth routes have throttle group. | Confirm OTP migrations/cache/rate-limiter store deployed, no stale 4-digit endpoints, IP and per-user abuse tests, role-specific token guards |
| C3 | TLS certificate validation bypass in Flutter | Current default-branch `lib/main.dart` for Patient/Doctor has no `HttpOverrides.global` or `badCertificateCallback`. | Verify actual installed Play/TestFlight binaries and network client transports on real devices, including invalid certificates, redirects and proxies; issue approved updates before considering live risk closed |

## High findings

| ID | Source status | Required evidence |
|---|---|---|
| H1 production secret/key exposure | **UNVERIFIED** — prior handover included production credentials and Passport private key. Repository hygiene is not proof of rotation. | Operator must rotate SMTP, database, Flutterwave/other gateway keys, Laravel APP_KEY with a controlled outage/session strategy, Passport RSA keys, Maps/Google/Firebase credentials and any other disclosed secrets; revoke old tokens and verify environment file permissions. Keep secrets out of the ticket and review package |
| H2 production debug/logging | **UNVERIFIED** — Namecheap live environment is not accessible for inspection from this code audit | Confirm `APP_ENV=production`, `APP_DEBUG=false`, conservative log level, no stack traces, no public `.env`/database dump, secure config/cache and backups |
| H3 doctor profile mass assignment | Source `Doctor::SELF_EDITABLE` is used by `apiUpdateDoctor` instead of `request->all()` | Staging role tests: doctor/provider cannot set `is_approved`, subscriptions, commissions, role/user IDs or privilege fields |
| H4 arbitrary reset and enumeration | Current `PasswordResetController` uses expiring reset tokens and generic messages; this PR redacts transport exception logs | Verify token expiry, one-time use, rate limits, email ownership, no account enumeration |
| H5 stored XSS | Public doctor/pharmacy views use `nl2br(e(...))` | Web regression with HTML escaping and content-security headers; no stored script execution |
| H6 unauthorized Zoom meetings | `ZoomOAuthController::ownAppointment()` binds meeting to signed-in doctor | Cross-doctor and patient authorization tests for all meeting create/read/edit paths |
| H7 | **Not itemized in supplied original audit.** | Request finding details; cannot assess an unidentified item |

## Medium and low findings

- **M1:** Address mutations and review endpoints have ownership checks in source; verify all admin/doctor schedule and delivery-address paths with cross-account staging tests.
- **M2: BLOCKER:** Patient prescriptions, report uploads and medicine-order PDFs are still written and served from web-accessible `public/` paths. Random filenames and filename-extension checks do **not** provide confidentiality. Plan private storage, authenticated per-appointment access, migration/purge of historic documents, deny anonymous direct URLs, client access changes and lifecycle/deletion tests before release. Do not merely add `.htaccess` to all images: public avatars/images would break. This needs a dedicated coordinated backend + app PR and production data migration.
- **M3:** Agora generation is bounded by `Appointment::hasOpenBooking`; test channel ownership, repeated calls and expiration.
- **M4:** New OTP service includes per-user limits and auth route throttle; test limiter persistence and bot abuse, plus separate password reset/login throttles.
- **M5:** Shared `appointment_details` and protected logout routes appear consolidated in current source; run `php artisan route:list` and check no ambiguous duplicate route names or methods.
- **M6:** Language names are guarded by `NAME_PATTERN`, and deletion uses `basename`; verify encoded traversal/Unicode/JSON payload testing.
- **L1:** AccountErasureService pseudonymizes personal/clinical fields and deletes local files but end-to-end erasure/retention of Firestore chat, caches and financial/legal records remains **unverified**.
- **L2:** Verify Care360 Plus token handling on actual installed binary, not just Patient secure storage.
- **L3:** Patient Home previously logged UID, precise location, and raw API exceptions. An isolated Patient performance/privacy branch removes these. Audit *all* other Patient/Plus loggers and native crash reporters before shipping.
- **L4:** Play upload keystore/signing fingerprint and iOS signing ownership still need confirmation by the authorized publishing developer.
- **L5:** Vendor license helper/obfuscated remote script requires independent vendor and code-integrity review. Do not strip legally required license checks blindly.

## Additional issue found after the October scan

Patient and Doctor `apiCompleteAppointment` / `apiStatusChange`
previously set `payment_status=1` when users completed care, and
non-COD settlements assumed they were paid. These were **not proven safe** by
the original gateway checks. On this branch they now change only the care
status and honor the pre-existing verified payment state. Repeated completion
and post-cancellation updates are rejected in the Doctor flow.

This PR also bounds appointment PNG-report input to eight images, each up to
6 MiB decoded, and rejects malformed base64. **Reports are still stored in
public paths** until M2 is resolved; size validation is NOT a confidentiality fix.

## Responsiveness review

Patient Home initiated two separate GPS reads at launch, requested location
and storage permissions before first paint, and created a repeating appointment
poll timer without a matching dispose. Raw UID/coordinates and HTTP errors
were logged. Patient PR (separate branch) defers GPS unless already granted,
uses cached/default address first, avoids nonessential startup permission
prompts, cancels polling on screen exit, and bounds Dio connection/receive
timeouts. Measure launch/first usable screen, time-to-list, p50/p95 API
latency, slow 3G and denied-location behavior on **real staging data**.
Source-only improvements are not measured speedups.

## Mandatory production operator steps

1. **Emergency operator review:** verify payments/OTP/TLS on live deployment,
   check whether public medical files can actually be fetched anonymously,
   restrict incident access and preserve forensic records. Treat confirmed
   exposures as incidents under the applicable privacy obligations.
2. **Rotate keys** from the vendor handover securely, with backups,
   rollback plan, session revocation and vendor access termination.
3. Staging: deploy and verify this backend payment PR and the separate
   account deletion POST PR #20. Do not change the deployed API contract
   before the compatible Patient build is ready.
4. Stage medical-file privacy migration (M2) before release; explicitly
   test patient/doctor/pharmacy ownership and no unauthorized direct download.
5. Run `php tools/test_payment_verification.php`,
   `php tools/test_payment_completion_contract.php`,
   `php tools/test_otp_verification.php`, `composer audit`, all
   Laravel unit/integration tests, and `php artisan route:list`; collect
   non-sensitive evidence.
6. Reconcile Patient UI/AI/language PR #29 with Patient security/performance
   changes, use a *connected staging* Android APK and actual iPhone build for
   manual smoke testing. The offline BlueStacks sandbox alone cannot verify
   authentication, media access, payments or clinic workflows.
7. Only the authorized developer/operator performs Play Store/App Store
   production signing and uploads after all blockers and test failures close.

## Never claim

- A passed source test proves live site security.
- HTTPS on the domain proves the shipped app verifies certificates.
- Password reset and account deletion URLs imply secure identity flows.
- Randomized filenames protect medical documents in a public directory.
- A screenshot of an offline demo is a functional or latency benchmark.

No production server, DNS, user records, gateway funds or publishing account
was changed as part of this source audit.
