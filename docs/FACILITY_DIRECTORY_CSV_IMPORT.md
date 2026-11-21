# Facility directory CSV staging

Use only records with documented reuse rights. Do not scrape proprietary maps listings. The importer never verifies or publishes a facility.

CSV headers (required): `type,name,state,address,latitude,longitude,source_name,source_id,source_url`. Source URL must be HTTPS. Use stable, nonempty source identifiers. Initial states are FCT, Nasarawa and Plateau; types are hospital and pharmacy.

Run `php artisan facility-directory:import /secure/path/facilities.csv` to validate without writing. Review provenance, duplicate candidates, location and licensing information separately. Then, on isolated staging only, use `php artisan facility-directory:import /secure/path/facilities.csv --commit` to create pending/draft records. Existing source IDs are not overwritten. The command rejects invalid files before writing, limits each batch to 1,000 records / 5 MB, and never creates accounts or enables transactions.

The super-admin review page remains the sole publication workflow. Before any production use, run migration/rollback, authorization, duplicate and visibility tests on staging; verify live facilities independently; and back up production. No source data or production deployment is included in this change.
