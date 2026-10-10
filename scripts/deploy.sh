#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NODE_BIN="${NODE_BIN:-node}"
NPM_BIN="${NPM_BIN:-npm}"
PHP_FPM_USER="${PHP_FPM_USER:-www}"
PHP_FPM_GROUP="${PHP_FPM_GROUP:-$PHP_FPM_USER}"
MODE=""

die() {
  printf 'Deploy failed: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage: bash scripts/deploy.sh (--frontend | --backend | --all) [--help]

Select exactly one deployment mode:
  --frontend  Build the admin, and publish dist/admin only.
  --backend   Install production PHP dependencies, run migrations,
              install Symfony assets, and clear the production cache.
  --all       Build and deploy both frontend and backend, and run migrations.

Run this script as root. Composer, npm, and Symfony asset installation run as
root; migrations and cache commands run as PHP_FPM_USER (default: www).
Backend and all modes run migrations without an interactive confirmation;
back up the production database before using either mode.

Configuration via environment:
  PHP_BIN         PHP 8.5 CLI binary (default: php)
  COMPOSER_BIN    Composer executable (default: composer)
  NODE_BIN        Node.js binary (default: node; version 22+ required)
  NPM_BIN         npm executable (default: npm)
  PHP_FPM_USER    Account that owns runtime cache and runs console commands
                  (default: www)
  PHP_FPM_GROUP   Runtime cache group (default: PHP_FPM_USER)
USAGE
}

resolve_command() {
  local command_name="$1" resolved
  if [[ "$command_name" == */* ]]; then
    [[ -x "$command_name" ]] || die "not executable: $command_name"
    printf '%s\n' "$command_name"
  else
    resolved="$(command -v "$command_name")" || die "command not found: $command_name"
    printf '%s\n' "$resolved"
  fi
}

while (($#)); do
  case "$1" in
  --frontend|--backend|--all)
    [[ -z "$MODE" ]] || die 'choose exactly one of --frontend, --backend, or --all'
    MODE="${1#--}"
    ;;
  --help|-h) usage; exit 0 ;;
  *) die "unknown option: $1 (choose --frontend, --backend, or --all)" ;;
  esac
  shift
done

[[ -n "$MODE" ]] || { usage >&2; die 'a deployment mode is required'; }

if [[ "$MODE" != frontend ]]; then
  PHP_BIN="$(resolve_command "$PHP_BIN")"
  COMPOSER_BIN="$(resolve_command "$COMPOSER_BIN")"
fi
if [[ "$MODE" != backend ]]; then
  NODE_BIN="$(resolve_command "$NODE_BIN")"
  NPM_BIN="$(resolve_command "$NPM_BIN")"
fi
command -v flock >/dev/null || die 'flock is required to prevent concurrent deployments'
((EUID == 0)) || die 'run this deployment script as root'
if [[ "$MODE" != frontend ]]; then
  [[ "$PHP_FPM_USER" == root ]] || command -v runuser >/dev/null || die 'runuser is required to run Symfony console commands as PHP_FPM_USER'
fi

cd "$ROOT"
[[ -f core/crud-skeleton/composer.json ]] || die 'run this script from a complete project checkout'
if [[ "$MODE" != frontend ]]; then
  [[ -e integration/backend/.env.prod.local ]] || die 'missing integration/backend/.env.prod.local'
fi

admin_link="$ROOT/integration/backend/public/admin"
if [[ -e "$admin_link" && ! -L "$admin_link" ]]; then
  die "$admin_link exists and is not a symlink; back it up and move it aside before deployment"
fi

admin_build="$ROOT/dist/admin"
staging_build="$ROOT/dist/admin.staging.$$"
backup_build="$ROOT/dist/admin.previous.$$"
cleanup_staging() {
  if [[ -d "$staging_build" ]]; then
    rm -rf -- "$staging_build"
  fi
}
trap cleanup_staging EXIT

lock_file="${DEPLOY_LOCK_FILE:-/run/lock/ns-ultimate-deploy.lock}"
exec 9>"$lock_file"
flock -n 9 || die 'another deployment is already running'

if [[ "$MODE" != frontend ]]; then
  php_version_id="$("$PHP_BIN" -r 'echo PHP_VERSION_ID;')"
  ((php_version_id >= 80400)) || die 'PHP 8.4 or later is required; PHP 8.5 is recommended'
fi
if [[ "$MODE" != backend ]]; then
  node_version="$("$NODE_BIN" --version)"
  if [[ "$node_version" =~ ^v?([0-9]+)\. ]]; then
    ((BASH_REMATCH[1] >= 22)) || die "Node.js 22 or later is required (found $node_version)"
  else
    die "could not parse Node.js version: $node_version"
  fi
fi

printf 'Deploying current checkout: %s\n' "$ROOT"

if [[ "$MODE" != frontend ]]; then
  "$COMPOSER_BIN" install \
    --working-dir="$ROOT/core/crud-skeleton" \
    --no-dev --prefer-dist --no-interaction --no-progress \
    --optimize-autoloader --no-scripts
fi

if [[ "$MODE" != backend ]]; then
  "$NPM_BIN" ci --prefix "$ROOT/core/crud-admin"
  "$NPM_BIN" run build --prefix "$ROOT/core/crud-admin" \
    -- --config "$ROOT/integration/admin/vite.config.ts" --outDir "$staging_build"
  [[ -f "$staging_build/index.html" ]] || die 'admin build did not produce its staging index.html'
fi

run_console() {
  if [[ "$PHP_FPM_USER" != root ]]; then
    runuser -u "$PHP_FPM_USER" -- env APP_ENV=prod APP_DEBUG=0 \
      "$PHP_BIN" "$ROOT/integration/backend/bin/console" "$@"
  else
    APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" "$ROOT/integration/backend/bin/console" "$@"
  fi
}

if [[ "$MODE" != frontend ]]; then
  cache_dir="$ROOT/var/cache/backend"
  install -d -o "$PHP_FPM_USER" -g "$PHP_FPM_GROUP" -m 0750 "$cache_dir"
  chown -R "$PHP_FPM_USER:$PHP_FPM_GROUP" "$cache_dir"
  chmod -R u+rwX "$cache_dir"

  printf '\nMigration status for the configured production database:\n'
  run_console doctrine:migrations:status
  run_console doctrine:migrations:migrate --no-interaction
  APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" "$ROOT/integration/backend/bin/console" \
    assets:install "$ROOT/integration/backend/public"
  run_console cache:clear
fi

if [[ "$MODE" != backend ]]; then
  if [[ -e "$admin_build" || -L "$admin_build" ]]; then
    mv "$admin_build" "$backup_build"
  fi
  if ! mv "$staging_build" "$admin_build"; then
    if [[ -e "$backup_build" || -L "$backup_build" ]]; then
      mv "$backup_build" "$admin_build"
    fi
    die 'could not publish the staged admin build; restored the previous build when available'
  fi
  if [[ -e "$backup_build" || -L "$backup_build" ]]; then
    rm -rf -- "$backup_build"
  fi

  temporary_link="$ROOT/integration/backend/public/.admin-link.$$"
  [[ ! -e "$temporary_link" && ! -L "$temporary_link" ]] || die "temporary link already exists: $temporary_link"
  ln -s '../../../dist/admin' "$temporary_link"
  mv -Tf "$temporary_link" "$admin_link"
  [[ -f "$admin_link/index.html" ]] || die 'public/admin does not resolve to the generated admin build'
fi

printf '\nDeployment finished (%s mode).\n' "$MODE"
if [[ "$MODE" != backend ]]; then
  printf 'Frontend link: integration/backend/public/admin -> ../../../dist/admin\n'
fi
printf 'Smoke-test the deployed part(s). This in-place deployment does not provide an automatic backend rollback.\n'
