# Care360 Plus — clinician encounter-note development contract

**Not production-ready until clinical/security UAT and migration testing.**

## Workflow
- The approved physician opens an appointment and selects **Clinical Notes**.
- Drafts are private to the treating physician, associated with that appointment and patient.
- Draft title/body values are encrypted by Laravel at rest and responses have `Cache-Control: private, no-store`.
- Finalizing a note locks it from ordinary edits. Corrections must be a new addendum, not silent editing or deletion.
- Only the owner physician is permitted to read, create or update notes. Patients and provider accounts cannot access the clinician notes API.
- This first phase does **not** grant patient access or allow cross-practice sharing; later work needs explicit consent, retention policy, legal review and audit logs.

## API contract
- `GET /api/doctor_notes?appointment_id={id}` returns owned appointment notes.
- `POST /api/doctor_notes` with `appointment_id`, `title`, `body` and optional `status=draft|final`.
- `PUT /api/doctor_notes/{id}` edits drafts or finalizes a draft.
- `DELETE /api/doctor_notes/{id}` intentionally rejects deletion.

## Safeguards still required before clinical release
- Encrypted data backup/restore and app-key lifecycle; key rotation will need data migration.
- Signed audit trail with author and timestamp, explicit addendum relationship, immutable snapshot/export.
- Review of professional licensing and telehealth documentation requirements in target jurisdictions.
- Record access audit (view/write) and minimum data retention requirements.
- Isolated synthetic-patient end-to-end tests of forbidden cross-doctor access, role gating, finalization, version history and UI.
- Medication prescribing is separate: should be limited to approved physicians with diagnosis/dose/allergy checks and independent e-prescription validation.
