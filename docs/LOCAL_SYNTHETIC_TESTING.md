# Care360 isolated synthetic login fixtures

This repository intentionally excludes the original CNU SQL dump because it may contain patient, clinician, authentication and payment data. Do not upload the raw SQL, database rows, real environment files or generated keys to GitHub or CI.

## Purpose

`database/seeders/K3SyntheticLocalSeeder.php` creates three synthetic identities only: a patient, a doctor and a provider. This is **not** a production seeder and is **not** a replacement for the initial database schema.

To use it, an operator must first create a disposable local MariaDB database called `care360_test`, import a *schema-only* snapshot with a validated migration baseline and safe nonproduction defaults, and install/configure Laravel Passport locally. The source export is not needed by the seeder.

The seeder refuses to run unless **all** of the following are true:

- `APP_ENV=local`
- Database name is exactly `care360_test`
- Database host is `127.0.0.1` or `localhost`
- `K3_LOCAL_SYNTHETIC_SEED=I_UNDERSTAND_TEST_ONLY`
- `K3_SYNTHETIC_PASSWORD` is a unique secret containing at least 16 characters

Run `php artisan db:seed --class="Database\\Seeders\\K3SyntheticLocalSeeder"` only after confirming the target configuration and executing `php artisan migrate:status` (read-only). The seeder does not make network calls, create admin accounts or copy sensitive records.

The IDs use the reserved `.example.test` domain and should never be used for real users. Passwords are hashed by Laravel. Do not publish the local test password or any Passport tokens.

The current patient/doctor/provider API login handlers require local Passport keys and a personal-access client. They also expect the doctor/provider role and profile rows, and patient registration expects a nonproduction `settings` row. The seeder creates identities/role links/profiles only; it expects the local schema and safe settings/roles/provider types to be present.

**No production database, Flutter app or staging deployment is changed by this file.** New migrations or changes in authentication must be reviewed and regression-tested before promoting anything beyond localhost.
