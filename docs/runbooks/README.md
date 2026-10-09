# Runbooks

Operational procedures for deploying and maintaining the composed application. These runbooks supplement, rather than replace, the architecture and deployment references.

- [Baota + Apache production deployment](baota-apache-production.md) ([简体中文](baota-apache-production.zh-cn.md)) — initial deployment on a Baota-managed Linux host with Apache 2, PHP 8.5-FPM, and MySQL.
- [Release and rollback](release-and-rollback.md) ([简体中文](release-and-rollback.zh-cn.md)) — repeatable release-directory deployment, migrations, traffic switch, and rollback.
- [Production incident triage](production-incident-triage.md) ([简体中文](production-incident-triage.zh-cn.md)) — diagnose common Composer, environment, `open_basedir`, permissions, database, and key failures.

## Scope and safety

- Examples use placeholders. Replace them with the actual domain, paths, runtime account, database version, and PHP-FPM socket; never paste secrets into Git or tickets.
- Do not run `make dev`, `make dev-reset`, `make docker-dev`, or `make docker-dev-reset` against production.
- Back up production data before schema changes. Migrations are an explicit release operation, not a web-worker startup hook.
- Keep web roots limited to `integration/backend/public`; serve the admin build separately at `/admin/`.
- The repository's current GitHub Actions workflow validates and builds the project; it does not deploy to Baota. A deployment job must be deliberately added and secured before claiming automated CD.

See also [the canonical deployment guide](../../DEPLOY.md) and [the Chinese translation](../../DEPLOY.zh-cn.md).
