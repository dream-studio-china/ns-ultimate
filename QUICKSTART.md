# Quickstart

This guide gets the composed admin and API running on a local workstation. For a non-containerized server deployment, see [DEPLOY.md](DEPLOY.md). A Docker Compose deployment baseline is available in [`infra/docker/README.md`](infra/docker/README.md). The Simplified Chinese translation is [QUICKSTART.zh-cn.md](QUICKSTART.zh-cn.md).

## 1. Install local prerequisites

Use PHP 8.5 for the documented development setup. The backend Composer manifest currently requires PHP `>=8.4`; keep CLI PHP, PHP-FPM, and the PHP extensions on the same supported version. Install:

- Git
- PHP 8.5 CLI with OpenSSL, PDO and PDO SQLite, ctype, iconv, intl, mbstring, XML/DOM, curl, fileinfo, tokenizer, and zip extensions
- Composer 2
- Node.js 22 LTS and its matching npm
- `make` (preinstalled on most macOS/Linux development machines)

Verify that the expected binaries are on `PATH`:

```sh
php -v
php -m
composer --version
node --version
npm --version
make --version
```

On macOS with Homebrew:

```sh
brew install php@8.5 composer node@22
```

After installation, ensure `php`, `composer`, `node`, and `npm` resolve to the intended versions. If using a version manager, select PHP 8.5 and Node.js 22 in the repository shell. On Linux and Windows, use packages or version managers appropriate to the OS and follow the official [PHP installation](https://www.php.net/manual/en/install.php), [Composer installation](https://getcomposer.org/download/), and [Node.js download](https://nodejs.org/en/download) guides. On Windows, WSL2 is the supported shell workflow; run the commands below inside WSL, not PowerShell.

If you use a remote MySQL database, PHP must also have `pdo_mysql`. Redis is only needed when the selected application features/configuration require it. The default local configuration uses SQLite and does not need a database server, but all upstream modules or migrations are not guaranteed to support SQLite.

## 2. Clone and install dependencies

```sh
git clone <repository-url> ns-ultimate
cd ns-ultimate
make install
```

`make install` installs the admin dependencies from the core npm lockfile and backend dependencies from the core Composer lockfile. It intentionally disables Composer auto-scripts; the root npm manifest only orchestrates these installs.

If Composer reports missing extensions, install them for the same PHP binary shown by `php -v`; do not bypass checks with `--ignore-platform-reqs`. If multiple PHP versions are installed, verify `which php`, `which composer`, and `composer diagnose`.

## 3. Initialize local configuration and start

```sh
make env-init
make env-check
make dev
```

The admin opens at `http://127.0.0.1:9528`; the API listens at `http://127.0.0.1:8000` and Vite proxies API requests to it. `env-init` creates ignored local override files, random development secrets, and a development-only JWT key pair. It does not overwrite existing local files. These values and keys are not suitable for deployment.

To choose different ports:

```sh
make dev ADMIN_PORT=9529 BACKEND_PORT=8001
```

When changing the backend port, also override `VITE_PROXY_TARGET` in `integration/admin/.env.local`, for example `VITE_PROXY_TARGET=http://127.0.0.1:8001`.

## 4. Run checks

```sh
make test-workflow   # Environment and Vite configuration tests
make test-admin      # Admin composition and core frontend tests
make test-backend    # Backend integration tests (isolated temporary SQLite)
make test            # All of the above
make type-check
make build
```

`make test-backend` uses a temporary SQLite database and removes it at shutdown. It does not migrate or connect to the local development database. `make migrate-status` and `make migrate` are explicit commands for the configured application database; review the target before applying migrations.

## 5. Debugging and common commands

```sh
make help
make console ARGS="about"
make routes ARGS="business-notes-list"
make container ARGS="--parameter=kernel.logs_dir"
make logs
make backend-debug   # Requires Xdebug in this PHP installation
make preview         # Serves the production admin build
```

For env-file precedence, database selection, API proxy behavior, and Xdebug settings, see [local development and debugging](docs/operations/development.md). For deployment-only configuration and release steps, see [DEPLOY.md](DEPLOY.md).
