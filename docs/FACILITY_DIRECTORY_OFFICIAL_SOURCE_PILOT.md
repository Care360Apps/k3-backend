# Facility directory: official-source pilot (FCT, Nasarawa, Plateau)

## Source candidates (not yet licensed or ingested)

1. Nigeria Health Facility Registry (Federal Ministry of Health): https://hfr.fmohconnect.gov.ng/ and developer documentation https://hfr.fmohconnect.gov.ng/developers . The documented read-only REST/FHIR APIs require an approved `X-API-Key`. **Obtain written confirmation of data reuse, attribution, caching, redistribution and refresh terms before copying records into Care360.** An API key alone does not establish reuse permission.
2. Pharmacy Council of Nigeria: https://pcn.gov.ng/ . Use its premises verification services for regulatory cross-checks. A directory listing does not establish current pharmacy licensure or authorization to dispense medicines.

## Pilot intake gates

- Request an HFR API key using its official developer page; store it in a secure secret manager, never in GitHub, logs, CSV files or screenshots.
- Confirm the API field names, pagination, request limits and the source's reuse terms from approved documentation/access; do not guess API response shapes.
- Start with a small sample per state and facility type, without publishing.
- Map only documented fields into `type,name,state,address,latitude,longitude,source_name,source_id,source_url`; preserve stable registry identifiers and source URLs. Do not invent missing coordinates, phone numbers or licenses.
- Before import, inspect for duplicates across source IDs and fuzzy name/address/location matches. Existing CSV importer deduplicates by source name and source ID, **not** fuzzy matching; perform the latter manually or in a separately reviewed tool.
- Run `php artisan facility-directory:import /secure/path/pilot.csv` without `--commit` first on an isolated staging environment; retain the output and review errors.
- Only after legal approval and staging checks, use `--commit` to create unpublished pending/draft entries in staging.
- Super-admin must independently check facility identity, address, coordinates, status and (for pharmacies) current PCN premises registration, with documented evidence before publishing.
- Check the patient app against staging on Android; ensure the API never returns pending or draft records.
- No automatic partner status, clinical booking, merchant registration, prescriptions, pharmacy ordering or payments.

## Release evidence checklist

- [ ] Written permission / applicable license for HFR data reuse recorded
- [ ] Approved HFR API access; no credentials committed
- [ ] Pilot CSV source IDs and provenance independently checked
- [ ] Duplicate review and coordinate validation complete
- [ ] Isolated staging migration and rollback pass
- [ ] Dry-run and staging draft import pass
- [ ] Review audit logs and publication authorization tested
- [ ] Android directory search, filters, map and pagination tested against staging
- [ ] Production backup and reviewed release approval

## Source outreach

Contact HFR through the official developer API-key request flow or hfr@health.gov.ng. Ask whether Care360 may retain, transform, attribute, display and periodically refresh facility records for FCT, Nasarawa and Plateau, including public app distribution; ask for rate limits, schema/versioning, approved attribution and revocation requirements. For pharmacies, confirm how current premises status can be verified with PCN.

**Status:** Source candidates identified; no credentials obtained, no real data imported, no staging deployment, and no production authorization.
