
# Care360 Android — connected staging authentication gate

**Status: staging infrastructure not yet verified.** This document and the smoke tool do not create a server, database, Firebase project or access production patients.

## Before the operator can test login

1. Deploy the Laravel backend to a separate, verified HTTPS staging host and distinct database, object storage, OAuth/Passport keys, email/SMS, notification and payment sandbox credentials. Keep real clinical and payment data out.
2. Import only an independently reviewed schema-only baseline and run the incremental migrations only after confirming the staging database is isolated. The source repository migrations are not a complete clean-install baseline.
3. Create three synthetic staging accounts for patient, doctor and provider with one newly generated private password (16+ characters). **Do not run the existing K3SyntheticLocalSeeder in staging**; it is deliberately guarded for LOCAL loopback only.
4. Provision separate staging Firebase apps for com.cnu.patient.k3staging and com.cnu.doctor.k3staging in one approved nonproduction Firebase project. Apply restrictive staging rules.
5. Confirm backend role isolation, OTP throttling, appointment ownership and payments-disabled/sandbox-only behavior before any wider pilot.

## Validate host and API without using production

From the backend repository on the authorized operator's trusted computer:

First, check only the URL and exact approved hostname. No network is used by default:

    python3 tools/k3_staging_login_smoke.py --base-url https://YOUR-STAGING-HOST/api/ --approved-host YOUR-STAGING-HOST

For the next step, **only after staging isolation is confirmed**:

- Set K3_STAGING_PROBE_ACK to ISOLATED_SYNTHETIC_ONLY in the operator's private local environment.
- Set K3_SYNTHETIC_PASSWORD to the private 16+ character password shared only by the three *synthetic* staging accounts. Do not paste it into chat, GitHub, commands or logs.
- Run the command again with --execute.

The checker uses certificate-verified HTTPS and allows **no redirects**. It sends three API POST requests:

| Role | API path | Synthetic account |
| --- | --- | --- |
| Patient | /api/login | patient@care360.example.test |
| Doctor | /api/doctor_login | doctor@care360.example.test |
| Provider | /api/provider_login | provider@care360.example.test |

Only redacted PASS/FAIL status is displayed. Tokens, passwords, user information and raw error bodies are never printed. This check does not test Firebase login, OTP, appointments or full Flutter navigation.

## Connect Android after those tests pass

Each Android repository needs these GitHub Actions settings (see the mobile runbooks for details):

| Kind | Name | Required value |
| --- | --- | --- |
| Secret | K3_STAGING_API_URL | Verified HTTPS staging base URL ending /api/ |
| Secret | K3_STAGING_ANDROID_FIREBASE_JSON_B64 | Base64 encoded *Android client* Firebase JSON for that .k3staging package |
| Variable | K3_STAGING_FIREBASE_PROJECT_ID | Approved separate Firebase project ID |
| Variable | K3_STAGING_API_VERIFIED | true only after isolation verification |

Run Actions → K3 opt-in staging builds → Android. Install the package named Care360 STAGING TEST alongside the Play app. Test registration, OTP, sign-in/out, role separation, dashboard and appointments with synthetic data. No Play Store update or production deployment is authorized here.

## Blocking conditions

- Staging database/host/Firebase not independently verified.
- Any production patient data, payment or messaging credentials in staging.
- Account or role authorization, OTP, TLS, secure file access or appointment access unresolved.
- Play upload key fingerprint and current versionCode not verified.

The local seeder is for disposable loopback development and must not be reused with a public staging database.
