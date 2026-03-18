#!/usr/bin/env sh
set -eu

timeout="${STARTUP_TIMEOUT:-90}"
sleep_seconds="${STARTUP_SLEEP:-2}"

wait_for_tcp() {
    name="$1"
    host="$2"
    port="$3"
    elapsed=0

    echo "Aguardando ${name} em ${host}:${port}..."

    while ! nc -z "${host}" "${port}" >/dev/null 2>&1; do
        elapsed=$((elapsed + sleep_seconds))
        if [ "${elapsed}" -ge "${timeout}" ]; then
            echo "Timeout aguardando ${name} em ${host}:${port}."
            exit 1
        fi
        sleep "${sleep_seconds}"
    done
}

wait_for_mysql() {
    elapsed=0

    while ! mysqladmin ping \
        --host="${DB_HOST}" \
        --port="${DB_PORT}" \
        --user="${DB_USERNAME}" \
        --password="${DB_PASSWORD}" \
        --silent >/dev/null 2>&1; do
        elapsed=$((elapsed + sleep_seconds))
        if [ "${elapsed}" -ge "${timeout}" ]; then
            echo "Timeout aguardando autenticacao no MariaDB ${DB_HOST}:${DB_PORT}."
            exit 1
        fi
        sleep "${sleep_seconds}"
    done
}

if [ "${WAIT_FOR_DATABASE:-1}" = "1" ] && [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    wait_for_tcp "MariaDB" "${DB_HOST}" "${DB_PORT}"
    wait_for_mysql
    echo "MariaDB acessivel."
fi

if [ "${WAIT_FOR_MAILPIT:-0}" = "1" ] && [ -n "${MAIL_HOST:-}" ] && [ -n "${MAIL_PORT:-}" ]; then
    wait_for_tcp "Mailpit" "${MAIL_HOST}" "${MAIL_PORT}"
    echo "Mailpit acessivel."
fi
