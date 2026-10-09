# Docker deployment foundation

This is a project-owned deployment baseline. It builds the business-composed admin and Symfony integration app from the repository root, then runs PHP-FPM, Nginx, MySQL, and Redis. Upstream Docker files under `core/crud-skeleton/` are reference material only; this stack does not modify or depend on their app entrypoint, environment layout, or Nginx document root.

## Prerequisites and first run

Install Docker Engine and the Docker Compose plugin. From the repository root:

```sh
cp infra/docker/.env.example infra/docker/.env
# Edit infra/docker/.env and replace every placeholder.
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml config --quiet
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml up --build -d
```

Create production-specific `APP_SECRET` and `REFRESH_TOKEN_SECRET` values, distinct from one another. Use hexadecimal MySQL passwords as noted in the sample so that the generated `DATABASE_URL` remains valid without additional URL escaping. Generate a deployment-specific RSA JWT pair, set absolute paths in `.env`, and restrict host file permissions so only administrators and Docker can read the private key. Do not use `make env-init` keys or commit the local `.env`.

Nginx binds to `127.0.0.1:8080` by default; place a host reverse proxy/load balancer in front to provide TLS. The admin is served at `/admin/`; backend routes are handled by the integration front controller. MySQL and Redis ports are not published to the host. Persistent named volumes hold database data, application runtime data, and uploaded files.

The worker is opt-in and consumes the `async` Messenger transport:

```sh
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml --profile workers up --build -d
```

Enable it only after configuring and validating the relevant transports and application handlers. This baseline does not start the upstream scheduler, provision external mail/SMS/WeChat services, terminate TLS, or automatically run schema migrations.

## Operations

```sh
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml ps
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml logs -f app nginx
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml exec app \
  php integration/backend/bin/console about
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml exec app \
  php integration/backend/bin/console doctrine:migrations:status
```

Review the migration status and take a backup before applying any schema changes. Use the integration console; do not run the core console. `docker compose down` preserves named volumes. `docker compose down -v` permanently deletes database, upload, and runtime volumes—use only when that data is intentionally disposable.

## Build model and limitations

`infra/docker/Dockerfile` builds admin assets with Node.js 22 and production PHP dependencies with PHP 8.5, then packages separate PHP-FPM and Nginx targets. Root `.dockerignore` excludes local env files, upstream core env files, dependency directories, generated output, and test fixtures. Compose requires real secrets and key paths instead of insecure defaults.

The sample is a starting point, not a turnkey security-reviewed production deployment. Pin base images by digest for release reproducibility, configure host firewall/TLS/monitoring/backups, review storage durability and upload access, and test upgrades and migration rollback. Run the local composition and backend checks before adopting it. See [DEPLOY.md](../../DEPLOY.md) for deployment concepts and [the Chinese translation](README.zh-cn.md).
