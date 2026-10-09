#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
command="${1:-help}"
shift || true
export HOST="${HOST:-127.0.0.1}" ADMIN_PORT="${ADMIN_PORT:-9528}" BACKEND_PORT="${BACKEND_PORT:-8000}"

admin() {
  cd "$ROOT/core/crud-admin"
  npm run "$1" -- --config ../../integration/admin/vite.config.ts "${@:2}"
}
console() { php "$ROOT/integration/backend/bin/console" "$@"; }

case "$command" in
  env-init|env-check) exec php scripts/env.php "$command" ;;
  dev-init) exec php scripts/dev-init.php ;;
  dev) exec node scripts/dev.mjs "$@" ;;
  admin) admin dev --host "$HOST" --port "$ADMIN_PORT" --strictPort "$@" ;;
  backend|backend-debug)
    options=()
    if [[ "$command" == backend-debug ]]; then
      php -r 'exit(extension_loaded("xdebug") ? 0 : 1);' || { echo 'Xdebug is not installed for this PHP binary.' >&2; exit 1; }
      export XDEBUG_MODE=debug,develop
      options=(-d xdebug.start_with_request=yes -d "xdebug.client_host=${XDEBUG_CLIENT_HOST:-127.0.0.1}" -d "xdebug.client_port=${XDEBUG_CLIENT_PORT:-9003}")
    fi
    # Bash 3 (macOS) treats empty arrays as unset under nounset.
    exec php ${options[@]+"${options[@]}"} -S "$HOST:$BACKEND_PORT" -t "$ROOT/integration/backend/public" "$ROOT/integration/backend/public/index.php" "$@"
    ;;
  build) admin build "$@" ;;
  preview) admin preview --host "$HOST" --port "$ADMIN_PORT" --strictPort "$@" ;;
  test)
    bash scripts/project.sh test-workflow
    bash scripts/project.sh test-admin
    bash scripts/project.sh test-backend
    ;;
  test-workflow) exec node --test scripts/*.test.mjs "$@" ;;
  test-admin)
    node --test integration/admin/*.test.mjs
    cd core/crud-admin
    npm test -- "$@"
    ;;
  test-backend) exec php core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml "$@" ;;
  type-check|lint) cd core/crud-admin; npm run "$command" -- "$@" ;;
  console) console "$@" ;;
  routes) console debug:router "$@" ;;
  container) console debug:container "$@" ;;
  cache-clear) console cache:clear "$@" ;;
  logs)
    log="var/log/backend/${APP_ENV:-dev}.log"
    [[ -f "$log" ]] || { echo "Log file does not exist yet: $log" >&2; exit 1; }
    exec tail -n 100 -f "$log" "$@"
    ;;
  migrate-status) console doctrine:migrations:status "$@" ;;
  migrate) console doctrine:migrations:migrate "$@" ;;
  *) echo "Unknown command: $command. Run make help." >&2; exit 2 ;;
esac
