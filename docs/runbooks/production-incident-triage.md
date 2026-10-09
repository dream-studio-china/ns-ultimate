# Runbook: production incident triage

## First response

1. Record the time, affected domain/routes, current release SHA, and recent deployment/config changes.
2. Check the Baota Apache and PHP 8.5-FPM error logs. Production Monolog writes to `php://stderr`; it does not create `var/log/backend/prod.log` by default. Log paths depend on panel configuration.
3. Preserve logs and the failed release. Do not run development reset commands or make broad ownership/permission changes.
4. If the issue began immediately after a release, follow [release and rollback](release-and-rollback.md); first check whether the database migration is compatible with the old code.

## Composer install reports a PSR-4 warning

Known warning in some upstream versions:

```text
Class App\Promotion\PromotionException located in
src/Promotion/Exception/PromotionException.php does not comply with psr-4 ... Skipping.
```

This means Composer omitted that class from its optimized autoload map; it is not by itself proof that installation failed. Check the Composer exit status immediately and verify `core/crud-skeleton/vendor/autoload.php` exists. The current project source has no other reference to this class; track the namespace/path correction upstream. Do not install dev packages in production as a workaround.

## `open_basedir` says `vendor/autoload.php` is missing

The autoloader may exist but PHP-FPM cannot read it. This app bootstrap also loads `core/`, `business/`, and `integration/`; allowing only `integration/backend/public` is insufficient.

- Keep Apache `DocumentRoot` at `<project>/integration/backend/public`.
- Extend this site's allowlist to the project root and exact external secrets/data paths, for example `<project>/:<secure-app-dir>/:/tmp/`.
- Do not disable `open_basedir` globally.
- Baota may set an immutable bit on `.user.ini`. Back it up and inspect with `lsattr`; if immutable, temporarily use `chattr -i`, edit, then restore `chattr +i`. Do not use `chmod 777`.

## `DebugBundle` class is missing with `--no-dev`

Production dependencies intentionally omit development bundles. The integration bootstrap chooses the environment before dotenv loading, and the tracked `.env` defaults to `APP_ENV=dev`.

- Ensure the actual web request has `APP_ENV=prod` and `APP_DEBUG=0` before bootstrap.
- For Apache, set them in this site's virtual host; for Nginx, pass per-site FastCGI parameters.
- Do not set these in a PHP-FPM pool shared by unrelated sites. If the web server does not pass them, use a dedicated pool for this site.
- CLI checks also need explicit `APP_ENV=prod APP_DEBUG=0`.

## Symfony cannot create `var/cache/...` or application logs are not visible

The integration Kernel uses repository-root `var/cache/backend/<env>`, outside the public document root. Symfony creates nested paths such as `prod/translations` automatically. In production, Monolog writes JSON to `php://stderr`, not to a `prod.log` file. The main `fingers_crossed` handler emits its buffered records when an error occurs and excludes 404/405.

1. Confirm the actual PHP 8.5-FPM user/group; Baota commonly uses `www`, but verify it.
2. Grant write access only to `var/cache/backend` and configured `APP_SHARE_DIR`; `var/log/backend` is not the production Monolog destination unless explicitly configured.
3. Check execute/traverse permission on all parent directories.
4. Check the Baota site's Apache/PHP-FPM error logs. If FPM does not capture worker stderr, inspect `catch_workers_output` for the site's pool. Avoid changing a shared pool without considering its other applications.
5. Do not make the whole repository writable or owned by the web user. If CLI cache warmup used a different owner, repair only the runtime cache directory.

## Database connection refused or wrong database

- Confirm the production `DATABASE_URL` host, port, schema, and MySQL `serverVersion`.
- Distinguish host-published ports from container-internal ports. A request running on the host and one running inside a Docker network can require different hostnames/ports.
- Confirm the database is private/reachable from the application host and credentials have access only to the intended schema.
- Do not run `make dev` to “test” production DB connectivity; development setup can create schemas and apply migrations. Use a read-only connectivity/console check with explicit production configuration.
- Before migrations, inspect `doctrine:migrations:status` and verify a restorable backup.

## JWT private key cannot be read

Confirm `JWT_PRIVATE_KEY_PATH` resolves to the intended production key and PHP-FPM can traverse the parent directories and read the file. Keep the key outside the public document root, owned by an administrator, and non-writable by PHP-FPM. Do not regenerate keys as a troubleshooting shortcut; rotation can invalidate tokens.

## Admin login or account creation

Production admins are created explicitly with the integration console after migrations:

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console \
  app:identity:user:create <email> <username> '<strong-one-time-password>' --admin
```

The command-line password may be visible in process listings/history. Use a controlled session and change temporary credentials after first login. Never use the development initializer on production.

## Closeout

After recovery, verify `/admin/`, API/authentication, database writes, logs, and enabled integrations. Record the cause, exact change, release/migration identifiers, and prevention action. Redact credentials, tokens, cookies, and personal data from incident notes.
