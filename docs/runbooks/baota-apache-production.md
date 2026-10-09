# Runbook: Baota + Apache production deployment

## Purpose

Use this procedure for a bare-metal production deployment managed by Baota (宝塔面板), with Apache 2, PHP 8.5-FPM, and MySQL. The frontend is a static Vite build at `/admin/`; the Symfony application is served through `integration/backend/public/index.php`.

This is not a Docker procedure. Do not run the development targets on a production host.

## 1. Preflight

Record the values below before changing the host:

| Setting | Example | Verify |
| --- | --- | --- |
| Domain | `example.com` | DNS points to the host; TLS is provisioned |
| Project root | `/www/wwwroot/example.com/ns-ultimate` | Contains `Makefile`, `core/`, `integration/`, and `business/` |
| PHP-FPM user/group | `www:www` | Inspect the PHP 8.5 pool; do not assume |
| PHP CLI | PHP 8.5 | `php -v` and extensions match FPM |
| MySQL version | actual server version | Used in `DATABASE_URL` `serverVersion` |
| Secret/key storage | outside the public web root | PHP-FPM can read, not write, secrets/private key |
| Shared data directory | durable path | PHP-FPM can write; backups are configured |

Install/check Apache, PHP 8.5-FPM, Composer 2, MySQL, and the required PHP extensions: `ctype`, `iconv`, `openssl`, `intl`, `mbstring`, `curl`, `fileinfo`, `xml`/`dom`, `zip`, and `pdo_mysql`. Install Node.js 22 if building on the server. Otherwise build `dist/admin/` in CI or on a trusted workstation and upload the artifact.

Check the selected command-line PHP as well as FPM. On Baota, `php` on SSH `PATH` may not be the same PHP version selected for the site. Select the PHP 8.5 CLI binary before running Composer or Symfony commands.

## 2. Place a release and install dependencies

Deploy the complete repository, including its Git subtrees and lockfiles, into a release/project directory. For manual or release-based deployments, use a non-root deployment account. The simple in-place script below is designed for a root-only Baota setup: Git, Composer, npm, and asset installation run as root; migrations and cache commands run as the PHP-FPM user. Keep production secrets, JWT keys, uploads, and durable application data outside the public document root; for release-based deployments, prefer shared paths outside individual releases.

From the project root, install production Composer dependencies:

```sh
composer install \
  --working-dir=core/crud-skeleton \
  --no-dev --prefer-dist --no-interaction --no-progress \
  --optimize-autoloader --no-scripts
```

Build the frontend with Node.js 22 if CI did not supply the build artifact:

```sh
npm ci --prefix core/crud-admin
npm run build --prefix core/crud-admin -- --config ../../integration/admin/vite.config.ts
```

The generated admin files must exist in `dist/admin/`. Preserve the public base path `/admin/` in Apache.

Composer may report the known upstream PSR-4 warning for `App\Promotion\PromotionException` in `src/Promotion/Exception/PromotionException.php`; it skips that class in the optimized map. Check the command exit status and `core/crud-skeleton/vendor/autoload.php`. Do not install Composer dev dependencies in production to silence this warning; track the namespace/path mismatch for upstream correction.

## 3. Create production secrets and environment

Create a protected environment file outside the release and link it to:

```text
<project-root>/integration/backend/.env.prod.local
```

Use unique production-only values. A minimal example is:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<unique-random-secret>
REFRESH_TOKEN_SECRET=<different-unique-random-secret>
DATABASE_URL="mysql://<app-user>:<url-encoded-password>@127.0.0.1:3306/<schema>?serverVersion=<actual-version>&charset=utf8mb4"
DEFAULT_URI=https://example.com
JWT_PRIVATE_KEY_PATH=/www/secure/<app>/keys/private.pem
JWT_PUBLIC_KEY_PATH=/www/secure/<app>/keys/public.pem
JWT_PASSPHRASE=
APP_SHARE_DIR=/www/secure/<app>/data
INVENTORY_ENABLED=0
ALIYUN_SMS_DRY_RUN=1
```

Configure mail, Redis, and external provider values only when the feature is enabled. Use a dedicated MySQL account, never `root`; do not expose MySQL publicly. URL-encode special characters in the database password.

### Generate JWT keys once

Do this only if production keys do not already exist. Key replacement can invalidate issued tokens.

```sh
KEY_DIR=/www/secure/<app>/keys
sudo install -d -o root -g www -m 0750 "$KEY_DIR"
sudo test ! -e "$KEY_DIR/private.pem" && sudo test ! -e "$KEY_DIR/public.pem"
sudo openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$KEY_DIR/private.pem"
sudo openssl pkey -in "$KEY_DIR/private.pem" -pubout -out "$KEY_DIR/public.pem"
sudo chown root:www "$KEY_DIR/private.pem" "$KEY_DIR/public.pem"
sudo chmod 0640 "$KEY_DIR/private.pem" "$KEY_DIR/public.pem"
```

Replace `www` with the actual PHP-FPM group. PHP-FPM needs read access to the private key but must not be able to modify it. Back up private keys securely.

## 4. Select production mode for this site only

The integration bootstrap selects `APP_ENV` before loading environment-specific dotenv files. The tracked `integration/backend/.env` defaults to `dev`; therefore `.env.prod.local` alone does not switch the runtime to production. The request environment must already contain `APP_ENV=prod` and `APP_DEBUG=0`.

For Apache, set these in this site's virtual host, not in a PHP-FPM pool shared by other sites:

```apache
SetEnv APP_ENV prod
SetEnv APP_DEBUG 0
```

Apply to both HTTP/HTTPS virtual hosts if Baota defines them separately. Confirm this Apache/PHP-FPM setup passes the variables to PHP. If not, create a dedicated FPM pool for this site; do not change a shared pool. Set the same variables explicitly on CLI commands.

## 5. Configure `open_basedir` narrowly

PHP must read the full project tree because bootstrap loads `core/`, `business/`, and `integration/`; it also needs access to external secret/key/data paths. Keep the Apache `DocumentRoot` at `integration/backend/public`—widening `open_basedir` does not require exposing the repository over HTTP.

For example, adapt a Baota site's allowlist to:

```ini
open_basedir=/www/wwwroot/<project-root>/:/www/secure/<app>/:/tmp/
```

Do not disable `open_basedir` globally. Baota may mark the site's `.user.ini` immutable. Back it up and inspect the attribute before editing:

```sh
FILE=/www/wwwroot/<project-root>/integration/backend/public/.user.ini
sudo cp -a "$FILE" "$FILE.bak"
sudo lsattr "$FILE"
```

If the `i` attribute is present, remove it only for the edit (`sudo chattr -i "$FILE"`), update the allowlist, then restore it (`sudo chattr +i "$FILE"`). If no immutable bit was set, do not add one. Never use `chmod 777`.

## 6. Configure Apache 2

In the Baota site's Apache virtual host, preserve the PHP 8.5 handler generated for that site and merge the following routing. Replace paths/domain. If HTTP and HTTPS have separate virtual hosts, configure each as appropriate.

```apache
<VirtualHost *:443>
    ServerName example.com
    # Keep the TLS/certificate directives generated by Baota for this site.
    DocumentRoot "/www/wwwroot/<project-root>/integration/backend/public"

    SetEnv APP_ENV prod
    SetEnv APP_DEBUG 0

    RedirectMatch 301 ^/admin$ /admin/

    <Directory "/www/wwwroot/<project-root>/dist/admin">
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /admin/index.html
    </Directory>

    <Directory "/www/wwwroot/<project-root>/integration/backend/public">
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>

    # Keep Baota's site-specific PHP 8.5 handler.
</VirtualHost>
```

The two fallback rules serve the admin SPA and route backend paths to Symfony. Do not expose the repository root, `.env*`, `vendor/`, `core/`, `business/`, or `var/` as the document root. Validate Apache configuration using the panel's test/reload controls or the host's Apache config-test command before reloading.

The deployment script creates `integration/backend/public/admin` as a relative symlink to `dist/admin`. Do not also add `Alias /admin/`; Apache should serve the symlink from the public document root. Keep `FollowSymLinks` enabled. For repeat updates, see [the in-place deployment script](#repeat-updates-with-the-deployment-script) below.

### Repeat updates with the deployment script

From the server checkout, as root:

```sh
cd /www/wwwroot/<project-root>
PHP_BIN=/www/server/php/85/bin/php bash scripts/deploy.sh
```

The script must run as root: Git, Composer, npm, and Symfony asset installation run as root; migrations and cache commands run as `PHP_FPM_USER` (default `www`) via `runuser`. Set `PHP_BIN` and, if needed, `PHP_FPM_USER`/`PHP_FPM_GROUP` for this server. The checkout must have no local changes to tracked files and be on `main`; set `DEPLOY_BRANCH` to update another branch. Untracked Baota-generated files are left in place; Git will refuse the fast-forward if an untracked file would be overwritten. The script fast-forwards from `origin`, installs production Composer dependencies, builds the admin with Node.js 22 into a staging directory, installs Symfony bundle assets, publishes the successful build at `dist/admin`, repairs ownership and permissions only under `var/cache/backend`, and clears the production cache. A failed frontend build leaves the current `dist/admin` untouched. The frontend directory replacement has a brief gap and is not fully atomic. The script does not run migrations by default.

Only after taking and verifying a database backup, migrations can be explicitly requested; the script prints migration status and requires typing `APPLY-MIGRATIONS`:

```sh
cd /www/wwwroot/<project-root>
PHP_BIN=/www/server/php/85/bin/php bash scripts/deploy.sh --migrate
```

This updates a live checkout in place: backend PHP files become current during the update, so use a low-traffic window. The build is staged before replacing the frontend, but the backend release is not atomic and has no automatic rollback. For stronger release isolation, use [release and rollback](release-and-rollback.md).

## 7. Grant only runtime-directory write access

The integration Kernel uses the cache path below. Production Monolog writes logs to `php://stderr`, not to a file under `var/log/backend`:

```text
<project-root>/var/cache/backend/<environment>/
```

The cache is under the repository root but outside the public document root. Symfony creates directories such as `prod/translations` automatically. Production error logs are emitted to PHP-FPM worker stderr; check Baota's Apache/PHP-FPM error logs and verify stderr capture if output is not visible. Only the cache and configured `APP_SHARE_DIR` paths need PHP-FPM write access. For a Baota account whose actual PHP-FPM user/group is `www:www`:

```sh
ROOT=/www/wwwroot/<project-root>
sudo install -d -o www -g www -m 0750 \
  "$ROOT/var/cache/backend"
sudo chown -R www:www "$ROOT/var/cache/backend"
sudo chmod -R u+rwX "$ROOT/var/cache/backend"
sudo install -d -o www -g www -m 0750 /www/secure/<app>/data
```

Replace `www:www` with the real PHP-FPM identity. Do not `chown -R` the whole repository to the web user. Keep code and `vendor/` read-only to PHP-FPM.

## 8. Back up, migrate, create the admin, and warm cache

Run from the project root with the PHP 8.5 CLI and production settings. Confirm `about` and migration status first:

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console about
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:status
```

Take and verify a MySQL backup before applying reviewed migrations:

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:migrate --no-interaction
```

Create the administrator explicitly; never run the development initializer:

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console \
  app:identity:user:create <email> <username> '<strong-one-time-password>' --admin
```

The password is a CLI argument and may appear briefly in shell history/process listings. Use a controlled SSH session and change the temporary password after first login.

Warm/clear cache with the correct production environment. Ensure the CLI cache command and PHP-FPM use compatible directory ownership; if the CLI creates cache files as the deploy user, restore PHP-FPM access only on the runtime cache directory.

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console cache:clear
```

## 9. Smoke test and maintain

After Apache/PHP-FPM reload, check:

- `https://<domain>/admin/` and a nested admin route load the SPA and assets.
- API routes reach Symfony and use the production database.
- Login, admin authorization, logs, uploads/shared data, and enabled integrations work.
- No public request can read environment files, keys, `vendor/`, or source files.

Take regular database and upload backups. Keep secrets out of CI build logs. See [release and rollback](release-and-rollback.md) for repeat deployments and [incident triage](production-incident-triage.md) for common failures.
