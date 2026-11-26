# Care360 Workforce — Patient-first marketplace integration architecture (proposal)
Date: 2026-11-07
Status: PRODUCT/SYSTEM DESIGN ONLY. Not implemented, tested, merged, or publicly launched.

## Product decision
- **Care360 Patient** is the visible discovery/app shell: Hospitals / Doctors / Providers / Pharmacies / Laboratories / **Workforce**.
- **Care360 Plus** remains the clinical professional tool for Doctors/Providers (teleconsultation, notes, prescriptions, virtual/home visits); **do not** add a third full recruiting app or turn Plus into the employer recruitment dashboard.
- Within Patient > Workforce, users select **Find Work** (student/graduate/qualified practitioner) or **Hire Talent** (hospital, pharmacy, diagnostic lab, health provider company, care agency).
- The same installation can present separate personas, but the **workforce authorization domain is independent of Care360 Patient clinical data** and Plus professional permissions. Existing Patient users can opt into an additional career profile without retyping public identity. New students can choose Workforce from registration; a candidate can be both patient and workforce participant but clinical records must never be copied into employer results. A hospital's existing facility listing does NOT grant employer rights.

## Critical architectural requirement before new accounts
The current Laravel backend uses a large common `auth:api` clinical route group; merely adding `employer` or `student` to existing `users` risks an employer token reaching legacy patient/clinical endpoints. Do not solve this by hiding buttons. **Implement separate workforce principal/guard and audience-scoped tokens, with explicit server-enforced role, tenancy, consent and resource ownership policies.** Reuse identity only after auditing ALL legacy patient APIs for role segregation. Prefer separate employer credentials/workforce guard for V1 until all clinical routes are fully hardened. Login/app UI does not prove authorization.

## App information architecture

### Patient Home
Care360 Patient home keeps other services intact. Add one **Workforce** service card with distinct briefcase/graduation-cap icon, alongside Hospitals, Doctors, Providers, Pharmacies and Labs; also add an accessible drawer entry (not repeated decorative cards in multiple places). Text: "Verified healthcare talent and jobs."

### Workforce landing (no sign-in required)
- Two clearly separate cards:
  - **Join Workforce**: student or graduate; verify qualification, work scope, availability.
  - **Hire Workforce**: employer/hospital; register organization, verify HR identity, post vacancies and find approved candidates.
- Public anonymous views: explanation, categories/aggregated counts, privacy and rules only. No bulk identifiable people directory or contact information to anonymous viewers.

### Candidate registration
1. If logged into Patient: tap "Create my Career Profile" with clear independent workforce privacy agreement; never populate health data or reuse private medical image.
2. If new: Workforce sign-up using standard account/OTP identity check or separate workforce-scoped account. Ask if **Student / Recent Graduate / Professionally Licensed**; type can be changed after verification.
3. Complete role(s) sought (care assistant, community health practitioner, phlebotomy support, laboratory assistant, caregiver, licensed doctor/nurse etc.), training institution, program, graduation/enrollment status, qualification, licence number only when applicable, professional scope and supervision, city/state, preferred shifts, expected pay range, start date and desired employment type.
4. Upload evidence to encrypted PRIVATE workforce credentials storage (not public images/upload or any patient PDF directory). Candidate controls consent to verification and the separate "Available for Work" switch. No profile is searchable until appropriate evidence is checked and publication explicitly enabled.
5. Verification states are independent: identity confirmed, school enrollment/completion, award authenticity, professional licence and expiration, employer-facing eligibility role, and availability. "Verified Student" is not "Licensed Professional." Pending/expired/revoked means no clinician-only vacancies.
6. Candidate dashboard: Career Profile, My Credentials, Available for Work, Jobs, Invitations, Applications, Work Messages, Privacy/Close Profile.

### Employer onboarding and search
1. Employer selects Hire Workforce, creates a dedicated organization account and submits CAC/facility identity, operating location, institution/facility status and responsible HR representative. Care360 admin approves before detailed candidate search.
2. Employer account roles: organization owner, hiring administrator, hiring reviewer (minimum permissions, separate approved employer per user); no clinical role inherited.
3. Employer dashboard: Find Verified Talent, Post Vacancy/Shift, Saved Profiles, Invitations, Applications, Hiring Pipeline, Organization Verification, Billing and Complaints.
4. Candidate search filters: **verified scope, qualification, profession, regulator/licence current status, availability/shift, state/city, experience and job type**. Do not filter by health, disability, diagnosis, ethnicity, religion or protected traits. Candidate profile cards display an optional professional photo, chosen display name, verified badge text with issuer and check date, role, general location, competency summary, available shifts and hiring preference, but no home address, medical records, phone, email or raw documents.
5. Employer can **Request to Chat / Invite to Apply / Request Interview**. Do not allow open contact lists, bulk scraping or cold direct messages. Candidate receives a work invitation with organization verified name, role, proposed pay, job type and expiry; candidate may accept/decline/block/report.
6. An accepted invitation creates a scoped Workforce conversation with owner/candidate/employer authorization checked at each message, attachment and notification. **Do not reuse clinical Patient/Doctor Firestore collections or generic Care360 patient chat**. Prevent patient or medical context, cross-tenant reads and leaked phone/email until the candidate chooses to share. Rate limit, audit and moderate abuse.
7. Hiring steps: Applied → Screened → Shortlisted → Interview → Offer → Accepted/Declined → Hired → Closed, with candidate-controlled profile visibility and an audit trail. Employment contract remains directly between appropriate parties in V1; no Care360 representation of clinical license or guaranteed employment.
8. V1 does not handle payroll, workers' insurance, immigration, professional credential issuance, assignment to actual patients or auto-claiming employment status.

## Backend partition proposal
Conceptual tables:
- `workforce_identities` (separate guard/principal; optional link to user with consent)
- `workforce_candidates`, `workforce_candidate_preferences`, `workforce_availability`
- `workforce_credentials`, `workforce_checks`, `workforce_verification_events`
- `workforce_organizations`, `workforce_organization_members`
- `workforce_jobs`, `workforce_applications`, `workforce_invitations`, `workforce_offers`
- `workforce_conversations`, `workforce_messages`, `workforce_reports`, `workforce_audit_events`
- `workforce_billing_plans`, `workforce_invoice_records`, `workforce_consent_records`
Separate guarded namespace e.g. `/api/workforce/v1/...` using distinct guard/policy checks rather than generic `auth:api`. Every endpoint scoped by employer ID and role; private/signed credential proofs only for explicitly permitted reviewers. Distinct database encryption keys/config where practical; no shared medical storage. Repository test contract: no workforce controller imports Appointment, Prescription, DoctorNotes, PatientMedicalFile or MindCheck service.

## Business model for Care360
- **Candidates: free to create a profile and apply**. Do not assume recruiting permission or impose a recurring pay-to-be-visible fee on jobseekers.
- **Approved employers pay Care360** for published vacancies, subscription access to a limited number of active listings/seats, or managed institution-to-employer recruitment plans; trial for a handful of verified employers.
- Per-hire fees or staffing/secondment/outsourcing are a later legal/commercial option only after verifying Federal Ministry of Labour recruiter/private employment agency licensing and employment-law obligations. No automatic percentage deduction from student salaries, indefinite training debt or promise of a job.
- All revenues billed in Care360 business accounts, **not SpeakOut NGO donations**. Financial reporting distinguishes sponsor ads from workforce hiring and actual care services.

## Recruitment eligibility and legal controls
- Nigerian authorities: Federal Ministry of Labour and Employment recruiter licence: https://labour.gov.ng/recruiters-licence/ ; NELEX guidance https://nelex.gov.ng/index.php/recruiters-license
- Professional status is verified separately from schooling, using primary source as applicable: CHPRBN https://app.chprbn.gov.ng/verify , NMCN https://myportal.nmcn.gov.ng/ , MLSCN https://hub.mlscn.gov.ng/
- Nigeria Data Protection Act 2023 applies to CVs, credentials, ID checks, availability, private messaging; obtain lawful basis, data minimization, privacy notices, independent retention periods, withdrawal and access logging. A candidate alone controls listing visibility and matching. Stage **18+ pilot**.
- Employer verification is not regulatory accreditation; make disclaimers precise. No platform authorization for independent regulated work merely because a school course is complete.

## Stage acceptance gates
**Hard dependency:** complete backend issue #29 critical/high security remediation, especially H1 secret rotation, H2 production debug proof, M2 legacy public medical files. No production candidates/employers until those close.
- Stage 0: source spec, threat model, data-protection impact review; no visible broken routes or mock "verified" badges.
- Stage 1: feature-flagged Patient Workforce entry and career profile, manually verified institutional pilot with fictional data. Test app role transition, sign-up, login, privacy, consent and qualification eligibility with fake students and employers.
- Stage 2: approved employer account, jobs, opt-in search and request-to-chat; 3–5 pilot employers. 2FA and report/block controls required.
- Stage 3: in-app billing after legal review and user research; more colleges, employer subscriptions, measurable hires and fairness reports.
- **Negative tests:** guest cannot view names/phones, candidate cannot verify themselves, employer cannot see candidate absent opt-in, employer A cannot read employer B's messages, revoked licence excluded, employer cannot read `/api/appointments` or `/api/medical-files`, patient clinic access never possible from workforce-only token, medical history not joined into resumes, file IDOR denied, reports investigated.
- KPIs: verified candidates, professionally licensed subset, available candidates, verified employers, job posts, interview invitations, candidate acceptance, hires, 30/90-day retention, identity fraud, false accreditation, cost per hire and revenue per employer.

## Existing code touchpoints, verified 7 Nov
- `Care360Apps/k3-patient/lib/Screen/Screens/Home.dart`: existing drawer already has Hospitals, Providers, Pharmacies, Labs and Doctor discovery. Introduce Workforce card/route here after backend and auth gates are in place.
- `Care360Apps/k3-patient/lib/Screen/Authentication/signup.dart`: Patient signup registers a patient account. Do not simply append a `student` value to the ordinary clinical `/api/register` payload.
- `Care360Apps/k3-doctor/lib/screens/auth/signup.dart`: Doctor/Provider onboarding already distinguishes those clinical personas. Do not merge workforce candidate permissions with medical provider approval.
- `Care360Apps/k3-patient/lib/Chat/chat.dart`: existing clinical messaging uses Firestore/Firebase authentication and doctor-linked peer routes; Workforce chat requires independent authorization/collections or dedicated backend messaging after review.
- `Care360Apps/k3-backend/routes/api.php`: existing `auth:api` covers many clinical endpoints. This is a real privilege-boundary concern; workforce accounts cannot share an unrestricted patient API bearer token. Authorization must be fixed server-side before registration goes live.

**Decision requested from product owner:** endorse Patient Workforce entry with separate workforce-scoped candidate and employer roles, approved employer-only candidate contact and employer-funded monetization. This document does not authorize public rollout.
