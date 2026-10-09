# Local development and debugging

Run commands from the repository root. Local development requires Node.js 22+, npm, PHP 8.4+, Composer, PHP OpenSSL, and PDO MySQL; MySQL must be running for first-time setup. The Docker workflow needs Docker Compose and Node.js/npm, but not host PHP, Composer, or MySQL. Dependency versions remain owned by each core lockfile.

## Docker PHP and MySQL development

Colleagues do not need PHP, Composer, or MySQL installed on the host. Docker Desktop/Engine with the Compose plugin and Node.js 22+ with npm are required; the admin Vite server remains a host process for fast hot reload. The development Compose file is separate from the production stack and uses development-only credentials and an isolated persistent MySQL volume.

```sh
npm ci --prefix core/crud-admin
make docker-dev
```

On first run, `docker-dev` builds the PHP image, starts MySQL, creates Docker-specific JWT keys, prompts for the admin identity, applies migrations, and creates the admin if absent. Before database changes, it requires typing the exact displayed `database:3306/ns_ultimate` target. Before Vite starts on every `docker-dev` launch, the saved admin email and initial password are printed. The password is also written to `var/docker-dev/keys/admin-initial-password.txt`; if changed later, this remains the initial password, not the current one.

On later runs, `docker-dev` simply starts the backend and local Vite at `http://127.0.0.1:8000` and `http://127.0.0.1:9528`; it does not repeat setup or apply new migrations. Use `make docker-migrate` when needed. Ctrl-C stops the Compose services. Source changes are bind-mounted. Composer dependencies live in a Docker volume and are installed when the PHP service starts. The development database is also persisted in a named volume; `make docker-dev-down` preserves it. To start over, run `make docker-dev-reset` and then `make docker-dev`. Reset only operates against a local Docker socket, pins the Compose project to `ns-ultimate-dev`, verifies the containers and MySQL volume labels, then requires typing `reset` before deleting the development DB volume and Docker-only JWT/password files.

Useful container-backed commands:

```sh
make docker-dev-up                 # auto-setup if needed; start DB/backend without Vite
make docker-dev-status
make docker-dev-logs ARGS="php"
make docker-console ARGS="about"
make docker-migrate-status
make docker-migrate
make docker-test-backend
make docker-dev-down
```

The MySQL container is published only on `127.0.0.1:3307` by default; change this with `DEV_MYSQL_PORT`. Backend and admin ports remain configurable with `BACKEND_PORT` and `ADMIN_PORT`. The Compose credentials and application secrets are for local development only—never reuse them outside this isolated stack. Do not run `docker compose down -v` unless you intentionally want to delete the development database and Composer cache volumes.

Install dependencies and run `make dev`. On the first run, the command initializes isolated local secrets, prompts for a running loopback MySQL connection and admin identity, applies migrations, and creates an admin account if absent. Local setup is restricted to `localhost`, `127.0.0.1`, or `::1` and the `ns_ultimate` database. Default MySQL settings are `127.0.0.1:3306` and user `root`; the password is hidden while typing. Before database changes, it requires typing the exact displayed `host:port/ns_ultimate` target. Existing admin accounts are not reset. Before Vite starts on every `make dev` launch, the saved admin email and initial password are printed. The password is stored in `var/local-dev/keys/admin-initial-password.txt` with mode `0600`; if changed later, this remains the initial password, not the current one. Dev-specific settings are stored in ignored `integration/backend/.env.dev.local`, separate from production env files.

The setup runs only once, after it succeeds. Stop the running apps before resetting. To fully reset the local development database and keys, run `make dev-reset`, confirm by typing the exact displayed endpoint and MySQL server identity, then run `make dev` again. Reset refuses production `APP_ENV`, requires the isolated `.env.dev.local`, restricts the connection to loopback and the exact development database name, and verifies the local development ownership token stored both in the checkout and target database. MySQL may report a different server hostname/port when running in a local container behind a published port; the checkout-specific token verifies ownership. Reset only touches dev-specific config/key files. The command requires PHP's `pdo_mysql` extension and installed backend Composer dependencies.

The admin is at `http://127.0.0.1:9528`; the backend is at `http://127.0.0.1:8000`. API requests are proxied by Vite. Ctrl-C stops both services, and a failed service stops the other. Run `make admin` and `make backend` in separate terminals if desired. Both bind to loopback by default; PHP's built-in server is for local development only.

`make env-init` can still be used separately to create generic local env files, random application/refresh-token secrets, and an unencrypted development-only RSA JWT key pair under `var/keys/`. The automatic `make dev` flow uses isolated files under `var/local-dev/` and `.env.dev.local`; Docker uses `var/docker-dev/`. Files have private permissions and existing files are never overwritten. An incomplete key pair fails rather than rotating keys silently. These paths are ignored by Git. Do not use development keys or configuration in production.

## Environment ownership and precedence

The composed apps load only project-owned env files, **not** those under `core/`. Existing setups relying on upstream env values must move their overrides to the integration directories. Root `.env` files are not loaded and shell scripts never source dotenv files.

- Admin: `integration/admin/.env`, `.env.local`, `.env.<mode>`, `.env.<mode>.local`, in increasing priority. Existing process variables have highest priority. For example, `make build ARGS="--mode staging"` selects staging overrides. Vite exposes `VITE_*` values to the browser: never put secrets there. Restart Vite after env edits. The proxy is local-only; deployments must route the API paths themselves.
- Backend: `integration/backend/.env`, `.env.local`, `.env.<APP_ENV>`, `.env.<APP_ENV>.local`, in increasing priority, with real environment variables taking precedence. Select the environment explicitly with `APP_ENV=test` or `APP_ENV=prod`; the bootstrap defaults to `dev`. Dotenv derives `APP_DEBUG` unless explicitly overridden. Tests skip `.env.local` and use `.env.test`; PHPUnit additionally overrides the database with a unique temporary SQLite file and deletes it after the run. The bootstrap supplies `NS_PROJECT_ROOT` for absolute dotenv path interpolation.
- `.env.local.example` files document overrides without real credentials. `make env-check` reports file presence and required backend variable status without printing values; installed backend dependencies are needed for value checks. It is a development sanity check, not a connectivity or production-readiness check.

To change ports, use `make dev ADMIN_PORT=9529 BACKEND_PORT=8001` and set `VITE_PROXY_TARGET=http://127.0.0.1:8001` in the admin `.env.local` (or as a process variable). Update backend `DEFAULT_URI` when needed. `HOST` controls the listen address, not dotenv values.

Without development setup, the backend's base configuration points to `var/data/dev.sqlite`, but the full project migration set is not SQLite-compatible. `make env-init` alone does not create a schema, provision Redis, or configure real SMS, mail, WeChat, or payment services. `make dev` therefore initializes against MySQL and asks for confirmation before migrations. For other manual migration operations, inspect the target first:

```sh
make migrate-status
make migrate
```

Manual migrations retain Symfony's interactive confirmation. Migrations do not run during installation, restarts after successful setup, `make env-init`, or tests; first-time `make dev`/`make docker-dev` and each reset followed by startup are explicit setup flows. Do not point routine development setup or validation at production databases.

## Debugging and validation

```sh
make help
make console ARGS="about"
make routes ARGS="business-dummies-list"
make container ARGS="--parameter=kernel.logs_dir"
make cache-clear
make logs
make backend-debug
make test-workflow
make test-admin
make test-backend
make type-check
make lint
make build ARGS="--sourcemap"
make preview
```

`ARGS` forwards CLI options to individual commands; test filters belong on `test-admin` or `test-backend`, not aggregate `test`. Local `make test-admin` includes upstream core frontend tests; the GitHub Actions workflow deliberately avoids all upstream core test suites and runs only project workflow/admin composition tests, integration/business PHPUnit tests, and the composed admin build. Console commands always use the integration Kernel. Logs follow `var/log/backend/<APP_ENV>.log`; a missing file is reported until the first log is written. Admin output is under `dist/admin/`, with a default production `/admin/` base path.

`backend-debug` checks that Xdebug is installed, enables `debug,develop`, and connects for each request. Configure your IDE to listen on port 9003. Override `XDEBUG_CLIENT_HOST` and `XDEBUG_CLIENT_PORT` as needed. Browser debugging uses Vite's development source maps; build source maps should remain local unless publishing them is intentional.
