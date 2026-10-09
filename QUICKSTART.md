# Quickstart

This guide gets the composed admin and API running on a local workstation. For a non-containerized server deployment, see [DEPLOY.md](DEPLOY.md). A Docker Compose deployment baseline is available in [`infra/docker/README.md`](infra/docker/README.md). The Simplified Chinese translation is [QUICKSTART.zh-cn.md](QUICKSTART.zh-cn.md).

## 1. Install local prerequisites

To avoid installing PHP, Composer, and MySQL on the host, use the [Docker development setup](docs/operations/development.md#docker-php-and-mysql-development); only Docker Compose and Node.js/npm are needed for that workflow.

Use PHP 8.5 for the documented development setup. The backend Composer manifest currently requires PHP `>=8.4`; keep CLI PHP, PHP-FPM, and the PHP extensions on the same supported version. Install:

- Git
- PHP 8.5 CLI with OpenSSL, PDO, and PDO MySQL, plus ctype, iconv, intl, mbstring, XML/DOM, curl, fileinfo, tokenizer, and zip extensions
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

Redis is only needed when selected application features/configuration require it. Local `make dev` uses MySQL because the complete project migration set is not SQLite-compatible; make sure a local MySQL server is running. If you do not want to install MySQL, use the Docker workflow linked above.

## 2. Clone and install dependencies

```sh
git clone <repository-url> ns-ultimate
cd ns-ultimate
make install
```

`make install` installs the admin dependencies from the core npm lockfile and backend dependencies from the core Composer lockfile. It intentionally disables Composer auto-scripts; the root npm manifest only orchestrates these installs.

If Composer reports missing extensions, install them for the same PHP binary shown by `php -v`; do not bypass checks with `--ignore-platform-reqs`. If multiple PHP versions are installed, verify `which php`, `which composer`, and `composer diagnose`.

## 3. Start local development

```sh
make dev
```

On first start, `make dev` initializes development-only secrets, prompts for the MySQL connection and admin identity, applies migrations, and creates the admin if absent. Before changing the database, it requires typing the exact displayed `host:port/database` target. Setup runs once; later `make dev` runs start both apps without repeating setup or applying migrations. The admin opens at `http://127.0.0.1:9528`; the API listens at `http://127.0.0.1:8000` and Vite proxies API requests to it.

Defaults are MySQL host/port `127.0.0.1:3306`, user `root`, and database `ns_ultimate`; password input is hidden. Admin defaults are `admin@example.com` / `admin`. Existing admin accounts are not reset. Each `make dev` launch prints the saved admin email and initial generated password before Vite starts. The password is stored in `var/local-dev/keys/admin-initial-password.txt` with mode `0600`; it is only the current password if it has not since been changed. Dev-only settings and the database URL are saved in ignored `integration/backend/.env.dev.local`, separate from production env files.

```sh
make dev-reset
make dev
```


Reset first verifies the database's development ownership token, refuses production `APP_ENV`, and only connects to loopback with the exact development database name. It displays the configured endpoint and MySQL server identity and requires typing the exact target before deletion. MySQL server identity can differ from the host (for example, when a local Docker container publishes its port); the checkout-specific token is the ownership check. Reset only changes the isolated dev env file and `var/local-dev/` files.

To choose different ports:

```sh
make dev ADMIN_PORT=9529 BACKEND_PORT=8001
```

When changing the backend port, also override `VITE_PROXY_TARGET` in `integration/admin/.env.local`, for example `VITE_PROXY_TARGET=http://127.0.0.1:8001`.

## 4. Run checks

```sh
make test-workflow   # Environment and Vite configuration tests
make test-admin      # Admin composition and core frontend tests (local command)
make test-backend    # Backend integration tests (isolated temporary SQLite)
make test            # All of the above
make type-check
make build
```

`make test-backend` uses a temporary SQLite database and removes it at shutdown. It does not migrate or connect to the local development database. `make migrate-status` and `make migrate` are explicit commands for the configured application database; review the target before applying migrations.

GitHub Actions runs the project workflow/admin composition tests, backend integration/business tests, and composed admin build. It intentionally does not run the upstream core test suites.

## 5. Debugging and common commands

```sh
make help
make console ARGS="about"
make routes ARGS="business-dummies-list"
make container ARGS="--parameter=kernel.logs_dir"
make logs
make backend-debug   # Requires Xdebug in this PHP installation
make preview         # Serves the production admin build
```

For env-file precedence, database selection, API proxy behavior, and Xdebug settings, see [local development and debugging](docs/operations/development.md). For deployment-only configuration and release steps, see [DEPLOY.md](DEPLOY.md).
