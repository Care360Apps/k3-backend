# Care360 consolidated backend — staging-only developer handover review
Date: 2026-11-07
Status: **DRAFT, NOT production-ready.** No deployment, account mutation, billing or customer communication has occurred.

## Branch foundation
This branch is stacked on backend security + Nearby Care review PR #27 (which already composes PR #25/#26 provider approval/presence, #20 authenticated account deletion, #21 payment/clinical upload protection, #24 private medical PDF download phase 1). It then reconciles sponsored campaign administration PR #18, nonclinical AI Guide PR #8, and the new Places (New) authenticated gateway.

**Do not merge independent older PRs on top of the combined branch.** Review the cumulative diff and migration order to avoid clobbering security changes.

## Changes in this combined branch
- `GET /api/banner` lists approved, active and in-date HTTPS-only sponsor/partner campaigns (max 30, no health targeting). Admin campaign management reuses the existing superadmin workflow and adds a start/end-date migration. Sponsor-vetting and image rights are manual.
- `POST /api/places/autocomplete` and `POST /api/places/details` are authenticated under `auth:api` and each limited to 20 requests/minute. They return minimal fields and use the fixed `places.googleapis.com` hostname, no arbitrary URL forwarding. Google credential stays server-side, in `config/services.php` and the private environment; it is **not** in `GET /api/setting` or Flutter. Missing key returns 503 and leaves Patient map-tap/manual-address selection functional.
- `POST /api/ai/guide` is authenticated and limited to 5 requests/minute. It only accepts fixed topic IDs and language codes. Curated safe navigation is the default; the Gemini feature flag is **OFF**. Absolutely no free text, medical records, symptoms, identity, booking data, tokens or payment data should reach the model. No generative AI use without a separate privacy/cost/clinical review.
- The existing protected medical-file, payment, doctor/provider eligibility, account-deletion, and opt-in Nearby Care code is preserved from PR #27.

## Deployment secrets (never commit)
```
K3_PLACES_SERVER_API_KEY=    # separate Places API (New) key, restricted to backend egress IP
K3_AI_GUIDE_ENABLED=false  # keep false until explicit approval
K3_AI_GEMINI_API_KEY=       # keep empty until explicit consent, privacy and budget review
```

`K3_PLACES_SERVER_API_KEY` is NOT the restricted Android Maps SDK key. Google Places New must be enabled and billed in the staging Google Cloud project; set spending quotas/alerts. Staging backend must be HTTPS for remote client use, except isolated LAN synthetic testing. Google Places session tokens are UUIDv4 from Patient and carried through autocomplete + details.

## Testing and rollout gates
1. Run `composer validate`, `php -l` for all PHP files and the offline `.github/workflows/quality.yml` suite, including sponsorship, AI, Places, payment, medical PDFs, provider permissions and Nearby Care.
2. With **synthetic only** authenticated accounts and staging Firebase, confirm /api/setting contains **no Google API key**, /api/places rejects unauthenticated calls, rate limits hold, and missing-key responses do not leak secrets. With dedicated Google staging key, test autosuggest, place details and API bills/quota. No real patient addresses in test.
3. Run the approved migrations on a copied disposable test database, confirm backups and idempotent rollback, and exercise superadmin sponsor activation/expiry. Paid ads must comply with applicable Nigerian advertising requirements and sector-appropriate medical reviews. Advertisers never receive patient health data.
4. Test PINNED Patient source and Plus source together. Patient contains a 10-second sponsor carousel and AI Guide; Plus must have no MindCheck Patient shortcut or Patient ad targeting. Verify English/Hausa/Igbo/Yoruba/French switch including fallbacks. **Only ~11% JSON key coverage** in non-English locales presently; do not market full localization.
5. Recheck Play Store publisher handoff blockers: publicly accessible legacy medical PDFs have **not** been migrated/cut off (GitHub issue #22). Hosting key rotation and Namecheap hardening are **not verified** (issue #23). Other payment/clinical, Play billing, provider onboarding and production permissions require signed-off integration tests.
6. Final publisher receives full source exports of **Patient, Plus and backend** plus hashes, environment checklist, tests, database migration plan and evidence screenshots. Developer handles production signing / Play upload. No real secrets belong in a source ZIP.

The Google Places gateway uses official v1 API New; use only the response fields required for suggestions and selected address. Do not reuse the insecure legacy REST key via public Settings.
