# Care360 AI Navigator: first controlled stage

## Purpose
A non-clinical Care360 navigation assistant for selected topics only:
`get_started`, `appointments`, `account`, `partners`.

**This is not a doctor, medical triage, diagnosis or treatment tool.**
No patient data, symptoms, free-form prompts, session history, accounts or booking details are sent to Gemini. User-authentication headers remain inside Laravel.

## HTTP contract
Authenticated request: `POST /api/ai/guide`
Body: `{"topic":"appointments","language":"ha"}`.
All five language codes are accepted: `en`, `ha`, `ig`, `yo`, `fr`.

Response includes `answer`, `source` (`curated` or `ai`), `disclaimer`, `topic` and `language`. This endpoint uses the Laravel `auth:api` guard and a five-requests-per-minute route throttle. The server responds with curated, English-only app guidance by default; **this is not evidence of a working AI connection or complete localization**.

## Model activation: strictly deferred
The default `K3_AI_GUIDE_ENABLED=false` does **not** call Gemini.
A server administrator must configure `K3_AI_GEMINI_API_KEY` as a secret
on the dedicated, isolated backend, not as a Flutter or public client
build variable. Never paste the key in GitHub, app builds, issue comments or chat.

Before enabling real AI, require:

1. Verified isolated backend, consent UI and updated Care360 privacy notice.
2. Separate provider data-processing review: retention, international transfers,
   provider policies and patient confidentiality controls.
3. Localized content review by Hausa, Igbo, Yoruba and French speakers.
4. Cost cap, abuse/rate-limit review, model output quality checks and fallback.
5. Explicit opt-in and transparency that answers are AI-generated. Never
   silently upload any health records or private chat.

The optional model request is limited to approved static app-navigation facts
and language selection. If the provider is unavailable, responses fail back
to curated guidance. **Generated outputs require additional review before a
live healthcare release.**

## Partner listings
Care360 Patient and Plus currently bundle one curated external-link card for
MindCheck, independently operated by SpeakOut Mental Health Outreach. Partner
submission, sponsorship, directory editing, moderation, commercial terms, and
user-tracking/analytics are future stages, not silently enabled by this PR.
External links must have explicit approval, accurate publisher names and
privacy notices. Do not mix Care360 patient identities with SpeakOut records.

## Critical dependency
The existing Laravel staging server and separate Firebase project must be
verified before claiming an end-to-end flow. See backend issue #6.
