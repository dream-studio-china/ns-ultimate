# Runbook: production release and rollback

## Current automation boundary

The current `.github/workflows/ci.yaml` checks the project on Node.js 22/PHP 8.5, runs composition tests, backend tests, and builds the admin. It does **not** upload an artifact or deploy to Baota. This runbook describes an operator-controlled release process; automate it only after adding protected deployment credentials, environment approvals, and equivalent checks.

## Release prerequisites

- A reviewed commit has passed CI and is available in the target repository.
- The deployment host has a non-root deploy account, PHP 8.5 CLI/FPM, Composer 2, and the required extensions.
- A production environment file and JWT keys are provisioned outside the release.
- Apache/PHP-FPM is site-configured with `APP_ENV=prod`, `APP_DEBUG=0`, correct `open_basedir`, public root, and writable runtime paths.
- The operator has a verified database backup and knows whether pending migrations are backward-compatible.
- `current` is already a symlink to a known-good release, or the first-deploy procedure explicitly handles its creation.

## 1. Identify and stage the release

Use a unique release ID (commit SHA is preferred) and a new directory. Do not overwrite the active release in place.

```sh
BASE=/srv/ns-ultimate
RELEASE_ID=<reviewed-commit-sha>
RELEASE="$BASE/releases/$RELEASE_ID"
```

Check that `RELEASE` does not already contain a different deployment. Deploy the exact reviewed commit, including Git subtrees. A CI artifact may provide the source and `dist/admin/`; the server still needs production Composer dependencies unless the artifact deliberately includes platform-compatible `vendor/` content.

If building on the server, use PHP 8.5 CLI and Node 22:

```sh
composer install \
  --working-dir="$RELEASE/core/crud-skeleton" \
  --no-dev --prefer-dist --no-interaction --no-progress \
  --optimize-autoloader --no-scripts

npm ci --prefix "$RELEASE/core/crud-admin"
npm run build --prefix "$RELEASE/core/crud-admin" \
  -- --config "$RELEASE/integration/admin/vite.config.ts"
```

If the CI artifact already contains the built admin, verify `$RELEASE/dist/admin/index.html` and its assets instead of rebuilding. Never put production secrets into the build job.

## 2. Link shared configuration and runtime storage

Keep production environment and data outside releases. Link the environment file into the release:

```sh
ln -s /etc/ns-ultimate/backend.env \
  "$RELEASE/integration/backend/.env.prod.local"
```

The per-site web request environment must separately provide `APP_ENV=prod` and `APP_DEBUG=0`; `.env.prod.local` alone does not select the environment before bootstrap. Ensure the Apache vhost or site-specific FPM configuration points to the active release and the PHP process can read the linked file and keys.

The Kernel uses repository-root `var/cache/backend/`. Production Monolog writes to `php://stderr`, so it does not require `var/log/backend/prod.log`; check PHP-FPM/Apache error-log capture. For release directories, provision the cache per release or link only that cache path to a shared writable location. Keep `APP_SHARE_DIR` persistent and outside disposable releases. Do not replace a non-empty directory with a symlink without inspecting and preserving its contents.

## 3. Validate, back up, and migrate

From the candidate release root, use the PHP 8.5 CLI and production environment:

```sh
cd "$RELEASE"
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console about
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:status
```

Review the exact target schema and migration list. Take a database backup and verify that it can be read/restored before migration. Then apply migrations as an explicit release action:

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:migrate --no-interaction
```

Do not run migrations in an automatic Apache/PHP-FPM startup hook. Do not assume schema rollback is safe; prefer a tested forward fix when a migration is irreversible.

## 4. Warm cache and inspect the candidate

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console cache:clear
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console debug:router
```

Ensure cache/log ownership permits PHP-FPM writes. Validate the admin build, `.env.prod.local` readability, key readability, database connectivity, and expected route table before switching traffic.

## 5. Switch traffic atomically

Keep the previous `current` target. Create a uniquely named temporary symlink in the same parent filesystem, then rename it over `current`:

```sh
NEXT="$BASE/current.next.$RELEASE_ID"
ln -s "$RELEASE" "$NEXT"
mv -Tf "$NEXT" "$BASE/current"
```

Use this only when `current` is a symlink and `NEXT` does not already exist. Confirm the Baota Apache vhost uses the stable `$BASE/current/...` paths. Reload/restart the site's PHP-FPM pool if needed to clear stale OPcache; reload Apache only when its configuration changed.

## 6. Smoke test and record

Check the HTTPS admin page and assets, API health/authentication, database-backed functionality, logs, uploads, and enabled integrations. Watch Apache, PHP-FPM, and application logs for errors. Record the release SHA, migration versions, backup identifier, operator, and health-check result without recording secrets.

## Rollback

Rollback only the code pointer when the prior release is compatible with the current schema:

```sh
PREVIOUS_RELEASE=<known-good-release-path>
NEXT="$BASE/current.rollback.$(date -u +%Y%m%dT%H%M%SZ)"
ln -s "$PREVIOUS_RELEASE" "$NEXT"
mv -Tf "$NEXT" "$BASE/current"
```

Then reload the site's PHP-FPM pool if required and repeat smoke tests. If the new migration is not backward-compatible, **do not** assume switching code back restores service. Follow the migration-specific restore/forward-fix plan and use the verified database backup only with explicit approval, since restore discards writes made after that backup.

Never delete the failed release, logs, or backup until incident review is complete. Do not use `git reset --hard`, `docker compose down -v`, `make dev-reset`, or broad recursive deletes as deployment rollback mechanisms.
