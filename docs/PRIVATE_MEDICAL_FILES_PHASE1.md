# Private Medical Files — Phase 1 (Care360 backend)

**Status: DRAFT — NOT DEPLOYED — DO NOT DECLARE MEDICAL FILES PRIVATE YET**

The 9 October 2026 Care360 security assessment identified unauthenticated
access to clinical PDFs and images stored inside `public/`. The current
source still returns public URLs from, among others:

- `UserApiController::apiAppointmentPrescription` (`prescription/upload`)
- `UserApiController::apiSingleAppointment` (PDF and report-image paths)
- `Appointment::getreportImageAttribute` (`images/upload`)
- lab/report and medicine-order PDF upload and download paths.

Random filenames, MIME validation and a webserver rule protecting PHP
execution **do not prevent unauthenticated downloads**.

## What this draft adds

- Authenticated `GET /api/medical-files/appointments/{appointmentId}/prescription`
- Patient may retrieve only prescriptions belonging to their appointment.
- Assigned doctor/provider may retrieve only the appointment they serve.
- Document must belong to the same patient and appointment.
- File is read **only** from `storage/app/private/medical/prescriptions`;
  the handler *never* falls back to a public directory.
- Canonical path, generated PDF filename, file type, no-store and access
  controls are checked before streaming.
- The endpoint returns 404 until a prescription has been safely migrated.
- The route does not change old API responses, add public file links, run
  database migrations, or affect the current published Flutter clients.

## Required staged continuation (DO NOT skip)

1. With authorized infrastructure access, inventory all clinical file
   references (not contents) and separate them from public marketing
   assets, avatars and provider images.
2. Build a private-storage write path for new prescriptions and each
   clinical file category. Stop writing protected media into `public/`.
3. Extend owner/assigned-doctor/assigned-pharmacy/diagnostic-lab checks
   to each document category. Use a separate endpoint for each record
   type; never use an arbitrary `?path=` parameter.
4. Implement a safe, repeatable, verified migration from legacy public
   storage, with backups, integrity/hash checks, audit log without
   patient data, rollback and correct ownership mapping.
5. Update Patient/Plus Flutter to call authenticated endpoints using
   its secure bearer token. Avoid public or persistent signed URLs.
   Check PDF/image viewers and download/save/offline behavior.
6. Verify nonowner and unauthenticated requests cannot fetch files,
   **including by direct old URL**, using synthetic data in staging.
7. In the correct Namecheap server configuration, block public URLs
   for clinical folders **after** the authorized client migration.
   Never blanket-block `images/upload` without splitting out legitimate
   public assets.
8. Purge CDN/browser caches, review old links, check account deletion,
   record retention and applicable healthcare privacy obligations.
9. Obtain authorized QA/security sign-off before a production release.

## Verification limitations

This PR includes source-only tests but **no live storage conversion or
authenticated integration test has yet been run**. Do not merge as a
complete resolution of M2. Tracking: backend issue #22 and privacy/security
issue #23; read the original October assessment securely.

Keep all database exports, PHI, URLs to real prescriptions, signing
credentials and server secrets out of GitHub and chat.