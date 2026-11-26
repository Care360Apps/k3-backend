# Care360 Plus — independent recheck of 9 October 2026 security assessment

**Assessment reviewed:** Care360 Plus Security Assessment, 9 October 2026, 3 pages; static audit of a vendor handover (Laravel Doctro v10; Patient and Doctor Flutter v7.2). **Review date:** 7 November 2026.

**Current source references:**
- Backend consolidated DRAFT PR #28, review/care360-full-staging-handoff-20261107, stacked on security/Nearby PR #27.
- Patient DRAFT PR #35, feat/patient-nearby-sponsor-10s-integrated-20261107.
- Plus DRAFT PR #28, feat/plus-ux-locale-crash-fix-20261107.

**Evidence rule:** “Code mitigation present” means the latest REVIEW BRANCH implements a relevant guard. It does **not** mean the change is merged, deployed, verified in production, penetrated-tested or present in the already-installed APK. No production credentials, systems or medical records were inspected. Treat this document as confidential.

**Report count discrepancy:** Cover reports 3 critical + 7 high + 6 medium + 5 low = 21. It identifies only six high findings (H1–H6); no H7 description is supplied. Only 20 named vulnerabilities can be assessed from this copy. Obtain the assessor's full H6 description and missing H7 before comprehensive closure.

## Critical (C1–C3)

| ID | Recheck status | Grounding in review code | Mandatory closure evidence |
|---|---|---|---|
| C1 Unverified payments and medicine order totals | **Code mitigation present; payment release unverified** | PaymentGatewayService::resolve clears client-supplied payment status, verifies gateway and refuses absent secrets; server-side medicine pricing exists. Completion paths changed not to infer paid from completed care. | Fake/unpaid/wrong-amount/wrong-currency/replayed/expired charges and subscriptions rejected, COD remains unpaid until settled; staged real-gateway verification, idempotency, role/owner checks, deployment key verification. |
| C2 OTP takeover | **Code mitigation present; abuse QA pending** | OtpService uses hashed six-digit OTP, 10-minute TTL, five failed attempts, resend controls; registration verification checks, auth route throttle. | Staging migrations, replay tests, same-account and distributed rate limits, role-scoped tokens, reset/verify race tests, confirmation installed apps use these routes. |
| C3 Disabled TLS validation | **Source fix present; shipped binary not verified** | Latest Patient and Plus lib/main.dart contain no MyHttpOverrides, HttpOverrides.global or badCertificateCallback bypass. | Inspect compiled APK/AAB and on-device traffic; invalid/expired/self-signed TLS certificates must be rejected, including native plugins and redirects. |

## High (H1–H7)

| ID | Recheck status | Source observation | Mandatory closure evidence |
|---|---|---|---|
| H1 Exposed production secrets and Passport private key | **OPEN — HARD BLOCKER** | Historical vendor handover contained database, SMTP, Laravel, OAuth/Passport, Maps and payment credentials. Repository hygiene cannot rotate previously exposed secrets. | Inventory recipients; rotate/revoke every affected credential and token, fix file ACLs, separate staging/production, check access history. Operator-authorized only, never send secrets to GitHub/chat. |
| H2 Production debug enabled | **OPEN — LIVE CONFIG UNKNOWN** | Source cannot confirm current Namecheap runtime APP_ENV/APP_DEBUG/LOG_LEVEL, error pages, backup/webroot and audit logging. | Authorized operator verifies production environment/debug off, safe error response, config cache, secure storage/backups and no exposed dumps. |
| H3 Doctor self-approval / commission mass assignment | **Code mitigation present; authorization QA pending** | DoctorApiController::apiUpdateDoctor uses Doctor::SELF_EDITABLE instead of request->all(). | As doctor and provider, attempt to set is_approved, user_id, commission, subscription and professional role fields; assert no mutation. |
| H4 Arbitrary password resets / enumeration | **Code mitigation present; integration QA pending** | API forgot password invokes Laravel PasswordResetController broker with generic response, expiring reset link, token consumption and token/session revocation. | Verify nonexistent/existing responses match, mail links expire and work once, unknown credentials rejected; per-account rate limits and notification privacy. |
| H5 Stored XSS in doctor/pharmacy public views | **Code mitigation present; browser QA pending** | Website templates use nl2br(e(...)) for doctor and pharmacy description. | Browser tests for HTML, script, attribute and encoded payloads in all public listings and admin views. |
| H6 Cross-doctor Zoom meeting access | **Code mitigation present; cross-role QA pending** | ZoomOAuthController::ownAppointment() binds meeting appointment to authenticated doctor. | Create/view/update endpoints, tampered IDs, cancelled appointments and patient/other provider contexts must be rejected. The supplied report truncates H6's full explanation. |
| H7 Unspecified in source report | **UNASSESSABLE — source report incomplete** | No finding description, impact or remediation appears in three-page uploaded report. | Obtain detailed assessor finding and reproduce/check against correct component. |

## Medium (M1–M6)

| ID | Recheck status | Source observation | Mandatory closure evidence |
|---|---|---|---|
| M1 Address, doctor scheduling, review and delivery ownership | **Code mitigations visible; full route matrix not verified** | apiDeleteAddress filters by auth user; Review::reviewableAppointment checks reviews; professional update_time scopes appointments/roles. | Synthetic patient A/B and doctor A/B requests to every mutation and address-charge path; assert 403/404 and unchanged state. |
| M2 Public prescription/test/report media | **OPEN — HARD BLOCKER** | New PatientMedicalFileController streams authorized PDFs from private storage ONLY, but Appointment::getreportImageAttribute still returns public images/upload URLs and legacy prescription/report writes persist. **No migrated/cutoff evidence.** | Safe storage for all medical categories, reversible migration with hashes, old URL denial at Namecheap/CDN, authenticated clients, backups, incident response if access was exposed. Do not claim fixed merely because a private GET route exists. |
| M3 Agora channel hijack | **Code mitigation present; live call QA pending** | API token issuance checks Appointment::hasOpenBooking for booked virtual consultation, with bounded token TTL. | Patient/doctor/channel ownership, expiration, repeated calls, cancelled booking, token leakage via push/logs and concurrent sessions. |
| M4 Login/OTP/password brute force | **Partial — source guards exist, infrastructure proof pending** | throttle:auth route group plus OTP account attempt/cooldown limits. | Confirm throttles survive multiple PHP workers/proxies, account-level rate controls, distributed attack simulation and privacy-safe error messages. |
| M5 Duplicate API routes | **Partially resolved; legacy mutation GET remains** | One GET appointment_details and one logout; delete-account is now intentionally POST + legacy GET for backward compatibility (not two identical method entries). | php artisan route:list and role smoke tests; retire state-changing legacy GET after installed-client migration. |
| M6 Admin language upload path traversal | **Code mitigation present; adversarial file tests pending** | NAME_PATTERN validation and basename in LanguageController; JSON upload bounded. | Encoded traversal, Unicode, null-byte-like names, symlink and unusual file type tests; real filesystem destination check. |

## Low (L1–L5)

| ID | Recheck status | Source observation | Mandatory closure evidence |
|---|---|---|---|
| L1 Account-deletion data retention | **Partial** | AccountErasureService removes some identifiers and clinical rows and deletes local files; may retain legally required financial data. | Test full deletion/retention matrix across Flutter, Laravel, Firestore/chat, backups, cache, analytics and third parties; data subject notice and erasure confirmation. |
| L2 Cleartext Doctor session tokens | **Code mitigation present; compiled app pending** | Plus SharedPreferenceHelper now uses FlutterSecureStorage for auth_token. | On-device migration from legacy unencrypted preferences, logout/revoke test, Android Keystore and iOS Keychain behavior. |
| L3 Verbose device logging | **Partial/unverified across all app variants** | Some known logging paths removed. No complete released-binary and native plugin log audit has been produced. | Search Dart, Kotlin/Java, Swift and crash tools for auth tokens, OTP, device IDs, precise GPS, call channels and PHI; inspect release logs and redact. |
| L4 Missing store release signing ownership | **OPEN — publisher dependency** | Gradle release build now rejects missing key.properties rather than silently using debug signing, but no evidence the original upload keystore/account ownership has been transferred. | Original developer confirms Play App Signing/upload certificate, release-key custody, continuity, AAB signature and recoverability. Never use staging debug signing for store. |
| L5 Obfuscated remote vendor licensing | **OPEN — requires vendor/legal review** | Legacy license helper capable of loading remote scripts was reported. No independent review, provenance or code-integrity signoff verified. | Confirm CodeCanyon license ownership, signed/allowed integrations, vendor dependency and remote-code execution risk; preserve valid licensing until reviewed. |

## Explicit release decision

**NO-GO for production or “all security fixed.”** A successful Patient Flutter test run does not close this report. No changes are confirmed deployed in Namecheap. The source quality workflows for private backend and Plus have not yielded successful execution evidence; a missing read-only Plus GitHub source token blocked one attempted independent run.

Hard dependencies before publishing: H1 production credential rotation, H2 runtime hardening, M2 clinical-file private migration / public URL cutover, H7 auditor clarification, Plus/backend integration tests, production payment and authorization tests, three-role BlueStacks verification, signing ownership, privacy and data-retention review.

The recruitment feature must remain staged separately: no employers or students should share patient/medical records tables; identity and occupational checks do not grant clinical practice permissions; no user-facing talent listings until the security blockers above close.

**Tracked at:** Care360Apps/k3-backend issue #29. This is a technical review, not an independent penetration-test attestation.
