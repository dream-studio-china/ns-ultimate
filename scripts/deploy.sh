#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NODE_BIN="${NODE_BIN:-node}"
NPM_BIN="${NPM_BIN:-npm}"
PHP_FPM_USER="${PHP_FPM_USER:-www}"
PHP_FPM_GROUP="${PHP_FPM_GROUP:-$PHP_FPM_USER}"
RUN_MIGRATIONS=0

die() {
  printf 'Deploy failed: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage: bash scripts/deploy.sh [--migrate] [--help]

Fast-forwards the configured branch, installs production Composer dependencies,
builds the admin with Node.js, links public/admin to dist/admin, and clears the
production cache. Database migrations are skipped unless --migrate is supplied;
that option requires an interactive backup confirmation.

Run this script as root. Git, Composer, npm, and Symfony asset installation run
as root; migrations and cache commands run as PHP_FPM_USER (default: www).

Configuration via environment:
  DEPLOY_BRANCH   Git branch to deploy (default: main)
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
    --migrate) RUN_MIGRATIONS=1 ;;
    --help|-h) usage; exit 0 ;;
    *) die "unknown option: $1 (use --help)" ;;
  esac
  shift
done

PHP_BIN="$(resolve_command "$PHP_BIN")"
COMPOSER_BIN="$(resolve_command "$COMPOSER_BIN")"
NODE_BIN="$(resolve_command "$NODE_BIN")"
NPM_BIN="$(resolve_command "$NPM_BIN")"
command -v git >/dev/null || die 'git is required'
command -v flock >/dev/null || die 'flock is required to prevent concurrent deployments'
((EUID == 0)) || die 'run this deployment script as root'
[[ "$PHP_FPM_USER" == root ]] || command -v runuser >/dev/null || die 'runuser is required to run Symfony console commands as PHP_FPM_USER'

cd "$ROOT"
[[ -f .git ]] || [[ -d .git ]] || die "not a Git checkout: $ROOT"
[[ -e integration/backend/.env.prod.local ]] || die 'missing integration/backend/.env.prod.local'
[[ -f core/crud-skeleton/composer.json ]] || die 'run this script from a complete project checkout'

current_branch="$(git branch --show-current)"
[[ "$current_branch" == "$DEPLOY_BRANCH" ]] || die "checked-out branch is '$current_branch', expected '$DEPLOY_BRANCH'"
if ! git diff --quiet || ! git diff --cached --quiet; then
  die 'tracked files have local changes; commit or safely move those changes before deployment'
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

lock_file="${DEPLOY_LOCK_FILE:-$(git rev-parse --git-path ns-ultimate-deploy.lock)}"
exec 9>"$lock_file"
flock -n 9 || die 'another deployment is already running'

php_version_id="$("$PHP_BIN" -r 'echo PHP_VERSION_ID;')"
((php_version_id >= 80400)) || die 'PHP 8.4 or later is required; PHP 8.5 is recommended'
node_version="$("$NODE_BIN" --version)"
if [[ "$node_version" =~ ^v?([0-9]+)\. ]]; then
  ((BASH_REMATCH[1] >= 22)) || die "Node.js 22 or later is required (found $node_version)"
else
  die "could not parse Node.js version: $node_version"
fi

previous_commit="$(git rev-parse HEAD)"
printf 'Deploying %s from origin/%s\n' "$ROOT" "$DEPLOY_BRANCH"
printf 'Current commit: %s\n' "$previous_commit"

git fetch origin "+refs/heads/$DEPLOY_BRANCH:refs/remotes/origin/$DEPLOY_BRANCH"
remote_ref="refs/remotes/origin/$DEPLOY_BRANCH"
git merge-base --is-ancestor HEAD "$remote_ref" || die 'local branch diverged from origin; refusing to reset or overwrite it'
git merge --ff-only "$remote_ref"
new_commit="$(git rev-parse HEAD)"
printf 'Updated commit: %s\n' "$new_commit"

"$COMPOSER_BIN" install \
  --working-dir="$ROOT/core/crud-skeleton" \
  --no-dev --prefer-dist --no-interaction --no-progress \
  --optimize-autoloader --no-scripts

"$NPM_BIN" ci --prefix "$ROOT/core/crud-admin"
"$NPM_BIN" run build --prefix "$ROOT/core/crud-admin" \
  -- --config "$ROOT/integration/admin/vite.config.ts" --outDir "$staging_build"
[[ -f "$staging_build/index.html" ]] || die 'admin build did not produce its staging index.html'

run_console() {
  if [[ "$PHP_FPM_USER" != root ]]; then
    runuser -u "$PHP_FPM_USER" -- env APP_ENV=prod APP_DEBUG=0 \
      "$PHP_BIN" "$ROOT/integration/backend/bin/console" "$@"
  else
    APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" "$ROOT/integration/backend/bin/console" "$@"
  fi
}

cache_dir="$ROOT/var/cache/backend"
install -d -o "$PHP_FPM_USER" -g "$PHP_FPM_GROUP" -m 0750 "$cache_dir"
chown -R "$PHP_FPM_USER:$PHP_FPM_GROUP" "$cache_dir"
chmod -R u+rwX "$cache_dir"

if ((RUN_MIGRATIONS)); then
  [[ -t 0 ]] || die '--migrate requires an interactive terminal and a verified database backup'
  printf '\nMigration status for the configured production database:\n'
  run_console doctrine:migrations:status
  printf '\nVerify a current, restorable database backup exists. Type APPLY-MIGRATIONS to continue: '
  IFS= read -r confirmation
  [[ "$confirmation" == 'APPLY-MIGRATIONS' ]] || die 'migration confirmation did not match'
  run_console doctrine:migrations:migrate --no-interaction
else
  printf '\nSkipping database migrations. Review status and back up the database before applying pending migrations.\n'
fi

APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" "$ROOT/integration/backend/bin/console" \
  assets:install "$ROOT/integration/backend/public"

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

run_console cache:clear

printf '\nDeployment finished.\n'
printf 'Frontend link: integration/backend/public/admin -> ../../../dist/admin\n'
printf 'Release commit: %s\n' "$new_commit"
printf 'Remember to smoke-test /admin/ and the API. This in-place deployment does not provide an automatic backend rollback.\n'
