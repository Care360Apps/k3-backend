# K3 Laravel backend — private development baseline

This clean copy excludes `vendor/`, `.git` history, real `.env`, OAuth keys, runtime storage, raw database dumps and `public/doctro.sql`.

## Safety
- The supplied database dump was **not** copied; it may contain sensitive healthcare records.
- `.env.example` is replaced with a minimal non-secret placeholder. Review original environment **variable names** securely before staging configuration.
- Production SQL dump must **never** be imported into CI/staging; use migration-based synthetic data instead.
- Use distinct database, mail, payment, and OAuth credentials for staging; verify Laravel route policies before public exposure.
- CI only validates manifests and PHP syntax; it does not make HTTP calls or modify any database.
- Run PHPUnit/integration tests only after synthetic fixtures and database isolation are in place.
- No production deployment has been attempted.
