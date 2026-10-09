# Bare-metal deployment

This runbook describes a conventional Linux host deployment without Docker. It deploys the frontend as static files and runs the Symfony API behind PHP-FPM and a production web server. Adapt the service-manager and web-server examples to the host OS. A project-owned Docker/Nginx foundation is documented in [`infra/docker/README.md`](infra/docker/README.md). The Simplified Chinese translation is [DEPLOY.zh-cn.md](DEPLOY.zh-cn.md).

## 1. Production topology and prerequisites

Use a supported PHP version satisfying `core/crud-skeleton/composer.json` (`>=8.4`; PHP 8.5 is the recommended baseline here), PHP-FPM, Composer 2, Node.js 22/npm for frontend builds, and Nginx or Apache. Provision a production-grade relational database supported by the chosen migrations (MySQL is used by the upstream production template), TLS termination, and outbound access for any enabled integrations. Do not assume the local SQLite database is suitable for production.

Install PHP extensions required by Composer and enabled application features. At minimum verify `ctype`, `iconv`, `openssl`, `intl`, `mbstring`, `curl`, `fileinfo`, `xml`/`dom`, `zip`, and the PDO driver for the selected database (for example `pdo_mysql`). Confirm both CLI PHP and PHP-FPM use the same PHP version and extensions:

```sh
php -v
php -m
composer --version
node --version
npm --version
```

Provision a dedicated non-root release/deploy user and PHP-FPM runtime user. Restrict inbound traffic to the web server; do not expose PHP-FPM, database, or Redis ports publicly. The PHP built-in server (`make backend`) is development-only and must not be used in production.

## 2. Build a release

Deploy an immutable release checkout containing the Git subtrees, project code, and lockfiles. Keep secrets, uploads, and writable runtime data outside the source checkout where practical, and make `current` point to the active release.

Install production dependencies without Composer development packages or auto-scripts:

```sh
composer install \
  --working-dir=core/crud-skeleton \
  --no-dev --prefer-dist --no-interaction --no-progress \
  --optimize-autoloader --no-scripts

npm ci --prefix core/crud-admin
npm run build --prefix core/crud-admin -- --config ../../integration/admin/vite.config.ts
```

The admin build is emitted to `dist/admin/` and uses `/admin/` as its production base path. Preserve this path in the web-server static mapping. Do not build with `--sourcemap` for public production releases unless publishing source maps is an explicit decision.

The integration backend console is `integration/backend/bin/console`; always use it rather than the core console so that the integration Kernel and business module registry are active. A package installation alone does not initialize the production app or run migrations.

## 3. Configure production environment and secrets

The integration bootstrap loads `integration/backend/.env`, then Symfony environment-specific files. It deliberately does not load `core/crud-skeleton/.env*`. Supply production values through the process environment or an untracked, tightly permissioned `integration/backend/.env.prod.local`. For release-based deployments, keep the actual secret file outside the release and link it into that location, for example:

```sh
install -d -o root -g www-data -m 0750 /etc/ns-ultimate
install -o root -g www-data -m 0640 /secure/source/backend.env /etc/ns-ultimate/backend.env
ln -s /etc/ns-ultimate/backend.env /srv/ns-ultimate/releases/<release>/integration/backend/.env.prod.local
```

Provision a dotenv-formatted file with these required values (replace every placeholder):

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<unique-long-random-value>
REFRESH_TOKEN_SECRET=<different-unique-long-random-value>
DATABASE_URL="mysql://<user>:<url-encoded-password>@<private-db-host>:3306/<database>?serverVersion=<supported-version>&charset=utf8mb4"
DEFAULT_URI=https://<public-domain>
JWT_PRIVATE_KEY_PATH=/etc/ns-ultimate/keys/private.pem
JWT_PUBLIC_KEY_PATH=/etc/ns-ultimate/keys/public.pem
JWT_PASSPHRASE=
ACCESS_TOKEN_TTL=3600
REFRESH_TOKEN_TTL=2592000
OTP_TTL=300
OTP_REDIS_DSN=redis://<private-redis-host>:6379
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
OUTBOX_PUBLISH_INTERVAL=5
MAILER_DSN=<production-mailer-dsn>
MEDIA_STORAGE_DEFAULT=local
APP_SHARE_DIR=/srv/ns-ultimate/shared/data
INVENTORY_ENABLED=0
ALIYUN_SMS_DRY_RUN=1
```

The database version string and credential URL encoding must match the actual database. Confirm whether Redis, mail, SMS, WeChat, payment, and inventory integrations are enabled before configuring their related variables. Keep `INVENTORY_ENABLED=0` unless the upstream production validation and business requirements explicitly approve enabling this preview-only feature. Set `ALIYUN_SMS_DRY_RUN=0` only after credentials, templates, quotas, and delivery behavior are validated. Optional WeChat/payment credentials belong only in the secret store/environment when those services are intentionally enabled. Consult [`core/crud-skeleton/.env.prod.example`](core/crud-skeleton/.env.prod.example) for the complete upstream integration variable list; do not copy its Docker-specific values blindly.

Generate a deployment-specific JWT key pair using an approved secret-generation procedure. For a basic unencrypted RSA pair:

```sh
install -d -o root -g www-data -m 0750 /etc/ns-ultimate/keys
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
  -out /etc/ns-ultimate/keys/private.pem
openssl pkey -in /etc/ns-ultimate/keys/private.pem -pubout \
  -out /etc/ns-ultimate/keys/public.pem
chown root:www-data /etc/ns-ultimate/keys/*.pem
chmod 0640 /etc/ns-ultimate/keys/private.pem /etc/ns-ultimate/keys/public.pem
```

Restrict private-key access to the application runtime that needs it, back up secrets securely, and plan key rotation (existing signed tokens may become invalid). Never run `make env-init` or `make dev-init` on production: they are development setup commands; `dev-init` creates a local database, runs migrations, and creates a development administrator. Never commit `.env.prod.local`, key files, database credentials, or provider secrets.

The application writes cache/log files under repository-level `var/cache/backend/` and `var/log/backend/`. Provision the required writable directories for the PHP-FPM user, while keeping application source and secrets non-writable by the runtime user. Select a persistent storage strategy for uploads and any data currently under `var/data/`; local ephemeral release storage is not a durable backup strategy. Review the core media configuration before exposing upload paths directly from the web server.

## 4. Validate configuration, warm cache, and migrate

Run commands as the release user with production variables available to the CLI, or use the linked `.env.prod.local`:

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console about
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console debug:router
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:status
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console cache:clear
```

Review the database host/schema, pending migrations, backups, and rollback plan before applying any schema change. Apply migrations as a deliberate release operation, with an authorized operator and a verified backup:

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:migrate --no-interaction
```

Do not run migrations automatically on each PHP-FPM worker start. Ensure the deployment's database role has only the permissions needed for the application and approved migrations. Validate the route table and check the application-specific endpoints before switching traffic.

## 5. Configure the web server and PHP-FPM

Point PHP-FPM to `integration/backend/public/index.php` as the only PHP entry point. Set the backend document root to `<release>/integration/backend/public`; never expose the repository root, `core/`, `.env*`, `vendor/`, or `var/` as web content. Configure TLS, request/body limits, timeouts, trusted proxy headers, and security headers for the actual environment.

Example Nginx server block (replace paths, domain, and FPM socket; validate with `nginx -t`):

```nginx
server {
    listen 443 ssl;
    server_name example.com;

    root /srv/ns-ultimate/current/integration/backend/public;
    index index.php;

    # Static admin build. The Vite production base is /admin/.
    location = /admin {
        return 301 /admin/;
    }
    location /admin/ {
        root /srv/ns-ultimate/current/dist;
        try_files $uri $uri/ /admin/index.html;
    }

    # Route backend requests through the integration front controller.
    location / {
        try_files $uri /index.php$is_args$args;
    }

    # Do not execute arbitrary PHP files from the document root.
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /srv/ns-ultimate/current/integration/backend/public/index.php;
        fastcgi_param DOCUMENT_ROOT /srv/ns-ultimate/current/integration/backend/public;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

This example requires confirmation against the installed Nginx/PHP-FPM versions and the application's actual asset/upload routes. If TLS terminates at a trusted upstream proxy, configure Symfony trusted proxies explicitly; do not trust arbitrary forwarded headers. Configure a process manager for any required Messenger workers or scheduled outbox publishing according to enabled features. Keep worker processes on the same release and environment as PHP-FPM.

## 6. Switch traffic, verify, and roll back

Before activation, verify that the frontend build, Composer install, env validation, database connectivity, cache warmup, and approved migrations succeeded. Switch the `current` symlink atomically, reload PHP-FPM/web server as appropriate, then check:

- `https://<domain>/admin/` returns the built admin page and its assets;
- API routes under the configured `/api` prefixes reach the Symfony integration Kernel;
- health checks, authentication, logs, uploads, and enabled external callbacks work as intended;
- unauthenticated/admin authorization boundaries behave as expected.

Keep the previous release and database backup until verification passes. Roll back the code symlink and reload services if needed. Database migrations may not be reversible; follow the migration's rollback/forward-fix plan rather than assuming a code rollback restores schema compatibility. Rotate credentials and JWT keys through an explicit, tested procedure.

## Deployment checklist

- [ ] PHP CLI and FPM versions/extensions satisfy Composer and runtime requirements.
- [ ] TLS, private database/Redis connectivity, backups, and service access controls are in place.
- [ ] Production env values and JWT keys are stored outside version control with least-privilege permissions.
- [ ] Admin build is served at `/admin/`; backend web root is only `integration/backend/public/`.
- [ ] Runtime writable paths and durable upload storage are provisioned.
- [ ] Production cache was cleared/warmed using the integration console.
- [ ] Migrations were reviewed, backed up, and applied as an explicit release step.
- [ ] External providers, workers, health checks, authorization, logs, and rollback were verified.
