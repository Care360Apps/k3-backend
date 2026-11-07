# Care360 real BlueStacks review — 3-role local test

**Status: PREPARED, NOT YET RUN.** This is a connected review of the actual
Care360 Patient, Care360 Plus and Laravel software. It is NOT Play Store
publishing and must not use any real patient data, live clinicians, real payments
or the production Namecheap database.

## Review concept

- Run the **actual Laravel backend** on the owner's Windows/XAMPP laptop,
  pointed at a separate database named `care360_test`.
- Seed only synthetic Patient, Doctor and Provider identities.
- Build actual Patient and Doctor/Provider Flutter APKs, each with the
  `.k3staging` Android suffix so production installations remain untouched.
- Point both apps at the **same laptop private IPv4, port 8000**.
- Test real API login and role/professional discovery, then an actual permitted
  synthetic booking workflow if the isolated local schema/config permits it.
- Record only synthetic screenshots and PASS/FAIL evidence.

The public synthetic Patient APK reviewed earlier has a **deliberately invalid
backend**; it cannot authenticate real test users. Do not use that as proof
of full functionality.

## Local backend prerequisites (operator-owned)

1. Back up only the **isolated test environment**. Confirm the backend source
   is the reviewed draft (not production), Composer/PHP and MySQL are installed.
2. Create an empty `care360_test` database. Import a **reviewed schema-only
   migration baseline**, not an exported production database with data.
   The repository migrations are not a complete fresh-install schema.
3. Configure `.env` privately: `APP_ENV=local`,
   `DB_HOST=127.0.0.1`, `DB_DATABASE=care360_test`,
   `K3_LOCAL_SYNTHETIC_SEED=I_UNDERSTAND_TEST_ONLY`,
   `K3_SYNTHETIC_PASSWORD` with a unique 16+ character password,
   separately generated local Passport keys, mail/payment test configuration.
   Never share the file or password with ChatGPT, GitHub or a developer chat.
4. Run `php artisan migrate:status` and inspect expected schema.
   Apply the locally reviewed incremental migrations, including
   professional-service presence, only to the test database.
5. Run `php artisan db:seed --class="Database\\Seeders\\K3NearbyLocalDemoSeeder"`.
   This refuses to seed any non-loopback DB, non-`care360_test` database or
   database containing unexpected user identities. It prepares three synthetic
   accounts only and requires proper role and provider type migrations.
6. Start `php artisan serve --host=0.0.0.0 --port=8000` from a trusted
   LAN computer and keep MySQL running. Only expose the server to trusted
   BlueStacks/private-LAN hosts; never port-forward it to the Internet.
   If local HTTPS is practical, prefer that; the temporary LAN Android
   debug build alone permits HTTP for a disposable test backend.
7. From the BlueStacks browser, confirm the laptop LAN host responds;
   `/api/` itself can 404, so test a known public API route or the
   synthetic login smoke tool rather than mistaking an API root 404 for
   total failure.

## App build prerequisites (operator-owned)

Use the public encrypted builder under
https://github.com/speakoutMentalHealth/care360-android-builder/actions

### Patient

Workflow: `Care360 Patient LAN-connected debug - encrypted output`
(`care360-patient-lan-encrypted.yml`).

It requires:
- Read-only Patient source secret `CARE360_SOURCE_READ_TOKEN` (already used
  in previous review builds; do not expose value).
- `K3_BUILD_OUTPUT_PASSWORD` (never transmit in chat).
- Staging Android Firebase *client* config secret
  `K3_STAGING_ANDROID_FIREBASE_JSON_B64` for `com.cnu.patient.k3staging`.
- Approved staging project variable `K3_STAGING_FIREBASE_PROJECT_ID`.
- Variable `K3_LAN_LOCAL_DATA_VERIFIED=true` set **only after** disposable
  local database is verified.
- Exact reviewed Patient source SHA, laptop private IPv4 (not public IP).

### Doctor/Provider

Workflow: `Care360 Plus BlueStacks review - encrypted debug`
(`care360-plus-bluestacks-review-encrypted.yml`).

For visual-only, select `offline`. To connect to the SAME local Laravel
API, select `lan` and provide:
- New read-only source token `CARE360_DOCTOR_SOURCE_READ_TOKEN`
  scoped **only** to `Care360Apps/k3-doctor`, Contents: Read.
- `K3_BUILD_OUTPUT_PASSWORD` as above.
- Separate Doctor staging Android Firebase *client* config secret
  `K3_STAGING_ANDROID_FIREBASE_DOCTOR_JSON_B64` for
  `com.cnu.doctor.k3staging`.
- The same approved nonproduction Firebase project variable.
- Verified `K3_LAN_LOCAL_DATA_VERIFIED=true` only after isolation check.
- Exact reviewed Doctor source SHA and the same laptop private IPv4.

**Do not use a Firebase Admin JSON or production Firebase project in either
builder.** Both Android staging registrations must be in the authorized
nonproduction Firebase project.

Both workflows upload **encrypted** short-lived debug output. Decrypt locally
with the existing standalone Windows decryptor and stored password; install
both `.apk` files in BlueStacks Pie 64-bit. Never publish a debug APK to Play.

## Synthetic account identifiers (never real users)

- Patient: `patient@care360.example.test`
- Doctor: `doctor@care360.example.test`
- Provider: `provider@care360.example.test`

All share a separate **locally supplied, private 16+ character test password**
created by the test operator; it is NOT embedded in this guide or any APK.

The professional service area is OFF by default. In Care360 Plus, explicitly
choose a fictitious, public service-area point near Keffi, confirm consent,
and activate the temporary shift. The Patient map should discover it only
when within the configured proximity. No personal residence or live GPS must
be exposed.

## Core acceptance sequence

1. Patient app cold start, onboarding and sign-in.
2. Doctor/Provider app sign-in using each distinct role; a Patient must
   not open professional functions.
3. Patient Home > Find nearby care > Accepting requests: initially empty
   for the synthetic professional.
4. Plus > Nearby Care: doctor enables *virtual* availability; provider enables
   permitted *home visit* and/or video consultation availability.
5. Patient sees only the approved, currently on-duty professional close to
   chosen area and can open the correct Doctor/Provider detail.
6. Home visit radius enforces the provider's limit. Doctor must not be offered
   as a home-visiting care provider. Distant search and expired shift excluded.
7. Patient enters the **existing** booking form on a synthetic profile. Only
   place a mock appointment if local timeslots/payment are configured and
   verified to use no real gateways or real clinical notifications.
8. Doctor views mock appointment where supported; virtual call and clinical
   notes/prescription tests remain blocked until test-only Agora/Zoom and
   private file storage are configured. Never infer success from UI alone.
9. Professional goes OFFLINE: Patient map stops listing the professional and
   stored service-point consent/coordinates are cleared.
10. Re-run unauthorized access, OTP, password reset, payment tamper, medical
    file ownership, slow network, offline, language, text contrast, navigation
    and device-permission tests. Document any failures by screen/API path and
    screenshot *without personal data*.

## Escalation gate

This is for owner review. Do not certify a Play Store-ready deliverable until:
- **#22 public medical-file migration** is complete and old anonymous access
  demonstrably denied.
- **#23 secrets/server hardening** is completed by authorized operator.
- Full Patient+Doctor connected staging regression, payments and auth tests pass.
- Original developer confirms unchanged production package IDs, upload-signing
  continuity and final `versionCode`/target Android API requirements.

The original developer alone signs and uploads the approved AAB to Play.
