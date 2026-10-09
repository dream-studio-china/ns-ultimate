#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
command="${1:-dev}"
shift || true
ready_file="$ROOT/var/data/.local-dev-ready"

configure_environment() {
  export APP_ENV=dev
  unset DATABASE_URL APP_SECRET REFRESH_TOKEN_SECRET JWT_PRIVATE_KEY_PATH JWT_PUBLIC_KEY_PATH APP_SHARE_DIR \
    NS_DEV_DB_HOST NS_DEV_DB_PORT NS_DEV_DB_USER NS_DEV_DB_PASSWORD NS_DEV_DB_NAME \
    NS_SKIP_DB_OWNERSHIP_MARKER NS_SKIP_DATABASE_URL_WRITE NS_SKIP_BACKEND_SECRET_FILE
  export NS_DEV_DATA_DIR=var/local-dev/data
  export NS_DEV_KEYS_DIR=var/local-dev/keys
  export NS_DEV_BACKEND_ENV_FILE=integration/backend/.env.dev.local
  export NS_DEV_APP_SHARE_DOTENV='${NS_PROJECT_ROOT}/var/local-dev/data'
  export NS_DEV_PRIVATE_KEY_DOTENV='${NS_PROJECT_ROOT}/var/local-dev/keys/private.pem'
  export NS_DEV_PUBLIC_KEY_DOTENV='${NS_PROJECT_ROOT}/var/local-dev/keys/public.pem'
  export NS_DEV_DATABASE_TOKEN_FILE="$ROOT/var/local-dev/database-token.txt"
  export NS_INITIAL_PASSWORD_FILE=var/local-dev/keys/admin-initial-password.txt
  export NS_ADMIN_EMAIL_FILE=var/local-dev/keys/admin-email.txt
  ready_file="$ROOT/var/local-dev/data/.ready"
}

print_admin_credentials() {
  local email_file="$ROOT/var/local-dev/keys/admin-email.txt"
  local password_file="$ROOT/var/local-dev/keys/admin-initial-password.txt"
  local email='admin@example.com' password='(not recorded; use the existing account password)'
  if [[ -r "$email_file" ]]; then IFS= read -r email < "$email_file" || true; fi
  if [[ -r "$password_file" ]]; then IFS= read -r password < "$password_file" || true; fi
  printf '\nAdmin login\nEmail: %s\nPassword (initial; may be outdated if changed): %s\n\n' "$email" "$password"
}

setup() {
  if [[ -f "$ready_file" ]]; then
    return
  fi
  if [[ ! -f core/crud-skeleton/vendor/autoload.php ]]; then
    echo 'Backend Composer dependencies are missing. Run make install first.' >&2
    exit 1
  fi
  php scripts/dev-init.php
  touch "$ready_file"
}

case "$command" in
  dev)
    configure_environment
    setup
    print_admin_credentials
    exec node scripts/dev.mjs "$@"
    ;;
  reset)
    php scripts/dev-reset.php
    ;;
  *)
    echo 'Usage: make dev [ARGS=...] | make dev-reset' >&2
    exit 2
    ;;
esac
