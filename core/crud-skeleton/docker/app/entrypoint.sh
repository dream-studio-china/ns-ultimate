#!/usr/bin/env sh
set -e

PRIVATE_KEY_PATH="${JWT_PRIVATE_KEY_PATH:-/var/www/html/var/jwt/jwt_private.pem}"
PUBLIC_KEY_PATH="${JWT_PUBLIC_KEY_PATH:-/var/www/html/var/jwt/jwt_public.pem}"
APP_ENV="${APP_ENV:-prod}"

if [ ! -f "${PRIVATE_KEY_PATH}" ] || [ ! -f "${PUBLIC_KEY_PATH}" ]; then
    if [ "${APP_ENV}" = "prod" ]; then
        echo "JWT keys are missing. Generate them on the host before starting production." >&2
        echo "Expected private key: ${PRIVATE_KEY_PATH}" >&2
        echo "Expected public key: ${PUBLIC_KEY_PATH}" >&2
        exit 1
    fi

    mkdir -p "$(dirname "${PRIVATE_KEY_PATH}")" "$(dirname "${PUBLIC_KEY_PATH}")"

    if [ ! -f "${PRIVATE_KEY_PATH}" ]; then
        echo "Generating development JWT private key at ${PRIVATE_KEY_PATH}"
        openssl genpkey -algorithm RSA \
            -out "${PRIVATE_KEY_PATH}" \
            -pkeyopt rsa_keygen_bits:2048
        chmod 600 "${PRIVATE_KEY_PATH}"
    fi

    if [ ! -f "${PUBLIC_KEY_PATH}" ]; then
        echo "Generating development JWT public key at ${PUBLIC_KEY_PATH}"
        openssl rsa -pubout \
            -in "${PRIVATE_KEY_PATH}" \
            -out "${PUBLIC_KEY_PATH}"
    fi

    echo "Development JWT keys are ready. They persist under the mounted var/ directory."
fi

exec docker-php-entrypoint "$@"
