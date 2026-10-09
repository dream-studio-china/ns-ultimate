# Local development and debugging

Run commands from the repository root. Use Node.js 22+, npm, PHP 8.4+, Composer, PHP OpenSSL, and PDO SQLite for the default development database and integration tests. Dependency versions remain owned by each core lockfile.

```sh
make install
make env-init
make env-check
make dev
```

The admin is at `http://127.0.0.1:9528`; the backend is at `http://127.0.0.1:8000`. API requests are proxied by Vite. Ctrl-C stops both services, and a failed service stops the other. Run `make admin` and `make backend` in separate terminals if desired. Both bind to loopback by default; PHP's built-in server is for local development only.

`make env-init` creates local env files, random application/refresh-token secrets, and an unencrypted development-only RSA JWT key pair under `var/keys/`. Files have private permissions and existing files are never overwritten. An incomplete key pair fails rather than rotating keys silently. Generated files and `var/data/` are ignored by Git. Do not use these keys or configuration in production.

## Environment ownership and precedence

The composed apps load only project-owned env files, **not** those under `core/`. Existing setups relying on upstream env values must move their overrides to the integration directories. Root `.env` files are not loaded and shell scripts never source dotenv files.

- Admin: `integration/admin/.env`, `.env.local`, `.env.<mode>`, `.env.<mode>.local`, in increasing priority. Existing process variables have highest priority. For example, `make build ARGS="--mode staging"` selects staging overrides. Vite exposes `VITE_*` values to the browser: never put secrets there. Restart Vite after env edits. The proxy is local-only; deployments must route the API paths themselves.
- Backend: `integration/backend/.env`, `.env.local`, `.env.<APP_ENV>`, `.env.<APP_ENV>.local`, in increasing priority, with real environment variables taking precedence. Select the environment explicitly with `APP_ENV=test` or `APP_ENV=prod`; the bootstrap defaults to `dev`. Dotenv derives `APP_DEBUG` unless explicitly overridden. Tests skip `.env.local` and use `.env.test`; PHPUnit additionally overrides the database with a unique temporary SQLite file and deletes it after the run. The bootstrap supplies `NS_PROJECT_ROOT` for absolute dotenv path interpolation.
- `.env.local.example` files document overrides without real credentials. `make env-check` reports file presence and required backend variable status without printing values; installed backend dependencies are needed for value checks. It is a development sanity check, not a connectivity or production-readiness check.

To change ports, use `make dev ADMIN_PORT=9529 BACKEND_PORT=8001` and set `VITE_PROXY_TARGET=http://127.0.0.1:8001` in the admin `.env.local` (or as a process variable). Update backend `DEFAULT_URI` when needed. `HOST` controls the listen address, not dotenv values.

The default database is `var/data/dev.sqlite`. Initialization does not create a schema, provision Redis, or configure real SMS, mail, WeChat, or payment services. SQLite is a lightweight local default, not a guarantee that every upstream module or migration is SQLite-compatible. Inspect and select the database before applying migrations:

```sh
make migrate-status
make migrate
```

Migrations retain Symfony's interactive confirmation. They never run during installation, startup, env initialization, or tests. Do not point routine validation at production databases.

## Debugging and validation

```sh
make help
make console ARGS="about"
make routes ARGS="business-notes-list"
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

`ARGS` forwards CLI options to individual commands; test filters belong on `test-admin` or `test-backend`, not aggregate `test`. `make test` runs workflow tests, core admin tests, and frontend/backend composition suites; it does not run the entire backend core suite. Console commands always use the integration Kernel. Logs follow `var/log/backend/<APP_ENV>.log`; a missing file is reported until the first log is written. Admin output is under `dist/admin/`, with a default production `/admin/` base path.

`backend-debug` checks that Xdebug is installed, enables `debug,develop`, and connects for each request. Configure your IDE to listen on port 9003. Override `XDEBUG_CLIENT_HOST` and `XDEBUG_CLIENT_PORT` as needed. Browser debugging uses Vite's development source maps; build source maps should remain local unless publishing them is intentional.
