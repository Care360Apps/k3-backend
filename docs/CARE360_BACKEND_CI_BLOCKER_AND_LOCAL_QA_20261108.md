# Care360 backend — pre-run GitHub Actions blocker and safe local QA

**Date:** 8 November 2026. **Branch:** `security/private-prescription-writes-staging-20261108` (draft PR #32). **Production access, data and payment credentials are prohibited for this test plan.**

## Verified CI evidence, not speculation

GitHub Actions run [42470968455](https://github.com/Care360Apps/k3-backend/actions/runs/42470968455) ended `failure` immediately. Its jobs `syntax-and-manifests` and `laravel-synthetic-feature` have no recorded steps or runner assignment. The Actions billing-timing API reported **0 ms billed** for each job. Re-running **failed jobs only** produced attempt #2, also `failure`, again with 0 ms billed and no steps. The most recent commits also triggered instant pre-step failures.

**Meaning:** These failures do not prove a PHP or Laravel test failure. The GitHub organization owner must inspect the banner/error on the run page and check **Care360Apps organization Settings → Billing and licensing** (billing/payment method, Actions budget and spend controls, usage limits) and **Settings → Actions → General** (workflows allowed, runner policies). Check repository **Settings → Actions → General** and check the repository's Actions enablement. Record the exact message/error and remediation. Do not change repository visibility or publish source code to work around billing.

The connected GitHub repository tools cannot view organization payment details or the private Actions run warning UI. **The specific pre-run cause remains unverified.** No code-only change should be represented as a fix for organization billing or runner access.

## Safe developer workstation path (source-only)

Use an authorized local checkout of this **private** branch. Install supported PHP **8.2+** (CI aims for PHP 8.3) and Git. From the repository root run:

```sh
php --version
php tools/run_backend_security_checks.php
```

The source QA runner lints `app/`, `config/`, `database/`, `routes/`, `tests/`, `tools/` and invokes offline contracts. It does **not** connect to MySQL, run migrations or call payment gateways. Keep `.env`, private keys, API tokens, database dumps and real patient data outside any uploaded logs.

Only after source checks pass, create a **separate synthetic Laravel** environment with Composer 2, PHP 8.3 + pdo_sqlite, and **no production credentials**. The PHPUnit feature suite expects `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `K3_ATOMIC_PAYMENT_RECEIPTS=true`, `K3_PRIVATE_PRESCRIPTION_WRITES=false` and `K3_SYNTHETIC_MEDICAL_MIGRATION=true`. Confirm these values in the isolated shell rather than reusing a developer's normal `.env`. For example in Bash, use ephemeral process environment variables only:

```sh
composer validate --no-check-publish
composer install --no-interaction --prefer-dist --no-progress
APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: \
CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array MAIL_MAILER=array \
K3_PRIVATE_PRESCRIPTION_WRITES=false K3_ATOMIC_PAYMENT_RECEIPTS=true \
K3_SYNTHETIC_MEDICAL_MIGRATION=true K3_WORKFORCE_PILOT_ENABLED=false \
APP_KEY='base64:REPLACE_WITH_NEW_TEST_ONLY_32_BYTE_RANDOM_KEY' \
php artisan test --filter='(PrivatePrescriptionAccessTest|PrivatePrescriptionWriteTest|LegacyPrescriptionStagingTest|ApprovedProfessionalAccessTest|AtomicPaymentReceiptClaimTest|WorkforcePilotTest)' --stop-on-failure
```

**Never run this command with an actual database or `APP_ENV=production`.** On Windows PowerShell, set the equivalent environment variables for the isolated terminal process; do not paste real secrets into chat or share screenshots of them. The placeholder APP_KEY must be replaced with a generated, disposable valid test key. Confirm DB config via `php artisan about` in the isolated test process before running PHPUnit.

## Mandatory acceptance gate before merging

1. Both GitHub Actions jobs allocate a runner and pass on the **latest PR head SHA**. An Actions retry does not stand in for a pass.
2. Synthetic PHPUnit tests cover private medical file permissions and suspension, provider credential approvals, workforce isolation, payment receipt collisions and callback integrity. Add real MySQL concurrency tests for two concurrent identical payment claims.
3. Payment gateway sandbox verification for Stripe, Paystack, PayPal and Flutterwave; Razorpay support only after owner binding. Reconcile historical paid subscriptions and in-flight hosted checkouts. Verify one paid record, one receipt claim, no double stock or subscription change and no duplicate external charges.
4. Review public clinical file exposure/migration, Firebase existing-session revocation and security rules, backup/rollback, credential rotation, mobile app integration and incident monitoring.
5. The release owner documents **GO** with evidence. Until then, **NO-GO**; do not turn on live feature flags, migrate real patient records, merge stacked PRs or deploy.

## Scope of this handover

Previous source-pattern checks and small standalone PHP syntax checks are **not** Laravel/MySQL end-to-end certification. No live production migration, release, store deployment, or customer payment has been performed in this branch.
