# Care360 facility directory — FCT, Nasarawa, Plateau

## Scope and boundaries
This feature uses a separate facility_directory table. A directory listing is not a pharmacy merchant, a hospital booking provider, or proof of professional licensure. Never create user accounts or enable medicine orders from an import.

## Data rules
- Allowed initial states: FCT, Nasarawa, Plateau.
- Required source provenance and a stable source identifier where available.
- All imported records default to draft/pending.
- Never bulk-copy proprietary maps listings without a suitable license.
- Verify coordinates, duplicate candidates, names and contact details before publication.
- Public queries must require BOTH listing_status=published and review_status=verified.
- Do not show a partner badge based solely on directory inclusion.
- Protect publication behind a dedicated administrator authorization boundary and log every status transition.

## Integration
- The legacy hospital table uses lat/lng; the legacy pharmacy table uses lat/lang. Directory uses latitude/longitude to avoid coupling.
- Preserve existing pharmacy medicine ordering, commission rules and merchant accounts.
- Add versioned, paginated read-only API and tests before connecting Patient Flutter UI.
- Pharmacy claims require authenticated proof of control, PCN verification, explicit approval and secure invitation; never email passwords.
- Existing pharmacy marketplace has documented checkout concurrency and settlement safeguards still pending (docs/PHARMACY_MARKETPLACE_PHASE1.md). Do not enable new partner transactions before those are resolved.

## Release gates
1. Isolated staging database and synthetic fixtures.
2. Run migration and rollback on staging; confirm existing routes remain functional.
3. Test draft/verified/published visibility, pagination, authorization and duplicate imports.
4. Integrate Patient UI; test on Android devices.
5. Import a reviewed, legally sourced pilot in each state.
6. Backup production and perform reviewed, reversible deployment.
No production deployment is authorized by this document.
