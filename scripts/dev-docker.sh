#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT/infra/docker/compose.dev.yaml"
COMPOSE=(docker compose --project-name ns-ultimate-dev -f "$COMPOSE_FILE")
command="${1:-help}"
shift || true
export DEV_UID="${DEV_UID:-$(id -u)}" DEV_GID="${DEV_GID:-$(id -g)}"
export HOST="${HOST:-127.0.0.1}" ADMIN_PORT="${ADMIN_PORT:-9528}" BACKEND_PORT="${BACKEND_PORT:-8000}"
ready_file="$ROOT/var/docker-dev/data/.ready"

php_run() {
  "${COMPOSE[@]}" run --rm --no-deps php "$@"
}

prepare_composer_vendor_permissions() {
  "${COMPOSE[@]}" run --rm --no-deps --user 0:0 php sh -lc \
    'test -n "$DEV_UID" || { echo "DEV_UID is missing from the PHP container environment." >&2; exit 1; }; mkdir -p core/crud-skeleton/vendor && chown -R "$DEV_UID" core/crud-skeleton/vendor'
}

print_admin_credentials() {
  local email_file="$ROOT/var/docker-dev/keys/admin-email.txt"
  local password_file="$ROOT/var/docker-dev/keys/admin-initial-password.txt"
  local email='admin@example.com' password='(not recorded; use the existing account password)'
  if [[ -r "$email_file" ]]; then IFS= read -r email < "$email_file" || true; fi
  if [[ -r "$password_file" ]]; then IFS= read -r password < "$password_file" || true; fi
  printf '\nAdmin login\nEmail: %s\nPassword (initial; may be outdated if changed): %s\n\n' "$email" "$password"
}

assert_local_docker_context() {
  local endpoint context
  if [[ -n "${DOCKER_HOST:-}" ]]; then
    endpoint="$DOCKER_HOST"
  else
    context="${DOCKER_CONTEXT:-$(docker context show)}"
    endpoint="$(docker context inspect "$context" --format '{{ (index .Endpoints "docker").Host }}')"
  fi
  case "$endpoint" in
    unix://*|npipe://*) ;;
    *)
      echo "Refusing Docker reset: active Docker endpoint is not a local socket ($endpoint)." >&2
      exit 1
      ;;
  esac
}

assert_compose_resources_owned() {
  local container project config_files expected_config volume_project volume_key
  expected_config="$COMPOSE_FILE"
  while IFS= read -r container; do
    [[ -n "$container" ]] || continue
    project="$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' "$container")"
    config_files="$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project.config_files" }}' "$container")"
    if [[ "$project" != ns-ultimate-dev || "$config_files" != *"$expected_config"* ]]; then
      echo "Refusing Docker reset: container $container is not owned by this development Compose file." >&2
      exit 1
    fi
  done < <("${COMPOSE[@]}" ps --all --quiet)

  local volume='ns-ultimate-dev_dev_mysql_data'
  if docker volume inspect "$volume" >/dev/null 2>&1; then
    volume_project="$(docker volume inspect --format '{{ index .Labels "com.docker.compose.project" }}' "$volume")"
    volume_key="$(docker volume inspect --format '{{ index .Labels "com.docker.compose.volume" }}' "$volume")"
    if [[ "$volume_project" != ns-ultimate-dev || "$volume_key" != dev_mysql_data ]]; then
      echo "Refusing Docker reset: volume $volume does not carry the expected development Compose labels." >&2
      exit 1
    fi
  fi
}

setup_if_needed() {
  [[ -f "$ready_file" ]] && return
  "${COMPOSE[@]}" build php
  prepare_composer_vendor_permissions
  "${COMPOSE[@]}" up -d --wait database
  php_run php scripts/env.php env-init
  php_run php scripts/dev-init.php
  touch "$ready_file"
}

case "$command" in
  up)
    setup_if_needed
    prepare_composer_vendor_permissions
    "${COMPOSE[@]}" up -d --build database php
    ;;
  down)
    "${COMPOSE[@]}" down
    ;;
  status)
    "${COMPOSE[@]}" ps
    ;;
  logs)
    "${COMPOSE[@]}" logs -f --tail=100 "$@"
    ;;
  reset)
    if ! [[ -t 0 ]]; then
      echo 'A terminal is required. Docker dev reset deletes this project development MySQL volume.' >&2
      exit 1
    fi
    assert_local_docker_context
    assert_compose_resources_owned
    if [[ -L "$ROOT/var" || -L "$ROOT/var/docker-dev" || -L "$ROOT/var/docker-dev/data" || -L "$ROOT/var/docker-dev/keys" ]]; then
      echo 'Refusing Docker reset: development data/key paths must not be symlinks.' >&2
      exit 1
    fi
    printf 'This permanently deletes only the ns-ultimate-dev MySQL volume and Docker-only JWT keys. Type reset to continue: '
    IFS= read -r answer
    if [[ "$answer" != reset ]]; then
      echo 'Cancelled; no changes were made.'
      exit 0
    fi
    "${COMPOSE[@]}" down
    volume='ns-ultimate-dev_dev_mysql_data'
    if docker volume inspect "$volume" >/dev/null 2>&1; then
      docker volume rm "$volume"
    fi
    rm -f "$ready_file"
    rm -f "$ROOT/var/docker-dev/keys/private.pem" \
      "$ROOT/var/docker-dev/keys/public.pem" \
      "$ROOT/var/docker-dev/keys/admin-initial-password.txt" \
      "$ROOT/var/docker-dev/keys/admin-email.txt"
    echo 'Docker development database and keys reset. Run make docker-dev to initialize again.'
    ;;
  console)
    php_run php integration/backend/bin/console "$@"
    ;;
  migrate)
    php_run php integration/backend/bin/console doctrine:migrations:migrate "$@"
    ;;
  migrate-status)
    php_run php integration/backend/bin/console doctrine:migrations:status "$@"
    ;;
  test-backend)
    php_run php core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml "$@"
    ;;
  shell)
    php_run sh "$@"
    ;;
  dev)
    setup_if_needed
    prepare_composer_vendor_permissions
    "${COMPOSE[@]}" up -d --build database php
    stop() {
      trap - INT TERM EXIT
      "${COMPOSE[@]}" down
    }
    trap stop INT TERM EXIT
    cd "$ROOT/core/crud-admin"
    print_admin_credentials
    npm run dev -- --config ../../integration/admin/vite.config.ts --host "$HOST" --port "$ADMIN_PORT" --strictPort
    ;;
  *)
    echo "Usage: make docker-dev (auto-setup on first run) | make docker-dev-reset | docker-dev-up/down/status/logs | docker-console | docker-migrate | docker-migrate-status | docker-test-backend" >&2
    exit 2
    ;;
esac
