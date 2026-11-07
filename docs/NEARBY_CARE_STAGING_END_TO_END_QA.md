# Care360 Nearby Care — first complete staging journey

Status: SOURCE READY FOR TESTING, NOT DEPLOYED. No test professional, live backend, production database or patient records accessed.

## Goal

Verify one strictly synthetic professional through OFFLINE → ONLINE → PATIENT FINDS NEARBY → DISTANT PATIENT DOES NOT MATCH → OFFLINE → NO LONGER VISIBLE.
This tests discovery and availability, not accepted jobs, appointments or payments.

## Staging prerequisites

1. Verify an isolated HTTPS staging Namecheap environment with synthetic test data, separate from real credentials, Firebase projects, medical records, payment keys and public medical file paths.
2. Review and integrate the companion provider approval PR #25 before backend presence PR #26. Review the legacy doctor ID schema before applying the new migration. Back up staging first.
3. Create a separate synthetic test professional with the proper Spatie role ('doctor' or 'provider'), matching doctor.user_role, a completed, active, approved profile, active subscription and eligible video/home-visit capability.
4. Choose a **public service-area point** (for a Keffi pilot, preferably a synthetic Keffi test coordinate), not a private residence or live device location.
5. Verify the synthetic professional starts **offline** and record its database professional ID securely. NEVER use a real patient or practitioner token.

## Operator commands

First run the offline no-network self-check:

    python3 tools/staging_nearby_care_smoke.py --offline-self-test

The connected smoke test reads six local process environment variables, supplied by an authorized operator through their private shell or secret manager:

| Variable | Required value |
|---|---|
| K3_STAGING_API_BASE_URL | Verified HTTPS staging/sandbox/test origin, ending in /api/ |
| K3_STAGING_SYNTHETIC_PROFESSIONAL_BEARER | Staging-only synthetic professional bearer token |
| K3_STAGING_NEARBY_CARE_SYNTHETIC_ACK | Exactly YES_SYNTHETIC_STAGING_ONLY |
| K3_STAGING_TEST_PROFESSIONAL_ID | Positive numeric doctor-table ID of the synthetic professional |
| K3_STAGING_TEST_SERVICE_LAT | Synthetic publicly published staging service-area latitude |
| K3_STAGING_TEST_SERVICE_LNG | Synthetic publicly published staging service-area longitude |

Then run:

    python3 tools/staging_nearby_care_smoke.py

Do not put tokens, keys, production service addresses, screenshots of environment variables, patient data or actual practitioner home coordinates in a repository, CI dispatch inputs or chat. This test refuses non-HTTPS and non-staging origins, prints only sanitized PASS/FAIL stages and attempts automatic OFFLINE cleanup even after a failure. If cleanup cannot be verified, immediately log into the staging Doctor/Provider app and switch the synthetic professional OFFLINE manually; do not continue pilot testing.

## End-to-end mobile acceptance

After the API smoke test passes, point a separate **staging-connected Patient and Doctor/Provider build** to the isolated backend (not the offline BlueStacks preview APK).

- Professional Home > Nearby Care: approve public service-area location, choose permitted service radius, start a 15–240-minute shift; ensure account status is confirmed by backend, not just the button tap.
- Patient Home > Find nearby care > Accepting requests: see the verified professional with a rounded location and approximate distance. Tapping opens the correct original Doctor/Provider detail page.
- Patient uses the existing clinical booking journey with **synthetic** timeslots and no live payment. Verify the professional is eligible for that consultation type. Do not equate accepting requests with a confirmed appointment.
- Patient changes chosen location outside 5km: the professional must not appear if out of radius. Denied GPS should keep manual city selection functional.
- Professional goes offline or shift expires: the Patient map removes the pin automatically, without exposing real-time movements.
- Attempt as unauthorized/unapproved, incomplete or inactive users; confirm no presence write, leaked profile, private clinic/home address, token or patient medical data.
- Check network errors and p50/p95 response time; verify physical Android and iPhone before developer receives store release source.

## Developer sign-off and handoff

Record the **immutable backend, Patient and Doctor/Provider commit SHAs**, migration revision and rollback plan, staging account type and synthetic ID (never token), source checks, test run statuses, device screenshots containing only synthetic content, medical-file authorization results and outstanding security issues.

The original developer handles Play Store and Apple App Store signing/upload. DO NOT deploy live while private medical file issue #22, infrastructure credential issue #23, payment security review and clinical workflow regression checks are unresolved.
