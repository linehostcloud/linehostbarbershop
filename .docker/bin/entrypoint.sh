#!/usr/bin/env sh
set -eu

umask 0002

mkdir -p \
    bootstrap/cache \
    database \
    storage/app \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

touch database/database.sqlite database/database_test.sqlite
chmod -R ug+rwX storage bootstrap/cache database

should_wait_for_dependencies() {
    case "${1:-}" in
        php-fpm|php-fpm8.3)
            return 0
            ;;
        php)
            if [ "${2:-}" = "artisan" ]; then
                case "${3:-}" in
                    migrate|migrate:*|db:*|queue:*|schedule:*|tinker)
                        return 0
                        ;;
                    test|pest|key:generate|about|help|list|config:*|route:*|view:*|event:*|optimize|optimize:*|storage:link|package:discover|make:*)
                        return 1
                        ;;
                    *)
                        return 1
                        ;;
                esac
            fi
            return 1
            ;;
        *)
            return 1
            ;;
    esac
}

if should_wait_for_dependencies "$@"; then
    /usr/local/bin/wait-for-dependencies
fi

if [ "${1:-}" = "php-fpm" ] || { [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ]; }; then
    if [ ! -f artisan ]; then
        echo "Projeto Laravel ainda nao instalado. Rode: docker compose run --rm setup" >&2
        exit 1
    fi

    if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
        echo "vendor/autoload.php nao encontrado. Rode: docker compose run --rm setup" >&2
        exit 1
    fi
fi

exec "$@"
