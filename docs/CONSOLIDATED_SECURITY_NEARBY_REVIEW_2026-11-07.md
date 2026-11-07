# Care360 consolidated security + Nearby Care — developer review branch

**7 November 2026 — DRAFT, NOT FOR PRODUCTION OR PLAY STORE.**

This branch starts from Nearby Care PR #26 (including approved-provider gate PR #25) and integrates source-only changes from independent backend security PRs:

- **PR #20:** authenticated patient account deletion route and role checks.
- **PR #21:** never mark unpaid appointments paid solely because a consultation was completed; bounded clinical file uploads and safe error handling.
- **PR #24:** ownership-authorized private-storage prescription PDF download endpoint (phase 1 only).
- **PRs #25–26:** approved/active providers, opted-in public service area, proximity search, expiring availability and retention cleanup.

**Conflict handling:** existing changes to `routes/api.php` and
`.github/workflows/quality.yml` were reconciled explicitly so all security,
Nearby Care, account deletion and authorized private PDF routes/tests remain
represented. Original PRs are left open for reviewer provenance.

## Mandatory release blockers

1. **Public medical files:** backend issue #22 is open. PR #24 adds just one
   authenticated download path. Old files under public prescription/report/image
   directories, report writers and mobile viewers have NOT been migrated;
   anonymous old-file access is not confirmed blocked.
2. **Live secrets / infrastructure:** backend issue #23. Rotation of vendor-exposed
   production credentials, Passport key hardening, safe APP_KEY migration,
   production debug settings and deployed behavior are unverified.
3. **Payments:** PR #21 needs actual sandbox gateway test, idempotency and
   server-side pricing validation across medicine/lab/appointment/subscription.
4. **Clinical user deletion:** PR #20 needs legal retention mapping and
   in-app + external web deletion request handling, including client QA.
5. **Mobile release tests:** compile both Patient and Doctor/Provider release
   variants under the original developer's signing identity and check existing
   installed-user upgrade, Android 16/API36 target, 5-language screens and
   real booking/teleconsultation/regression paths.
6. **Nearby Care:** test consent, professional approval, on-duty expiry,
   published location erasure, provider service radius, disconnected GPS and
   synthetic professional online → nearby discovery → offline journey.
7. **Quality:** Private GitHub Actions have not produced accessible passing
   staging evidence for the consolidated branch. Run PHP syntax, Composer,
   Laravel tests and database migration up/down on isolated synthetic staging.

This branch is a **single code-review baseline**, not a release candidate.
Do not send a Play Store AAB or change production until the owner and
independent security reviewer approve all blockers.

The original developer handles Play Store submission and signing exclusively.
