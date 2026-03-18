#!/usr/bin/env sh
set -eu

project_root="/var/www/html"

if [ -f "${project_root}/artisan" ]; then
    echo "Laravel ja instalado em ${project_root}."
    if [ -f "${project_root}/composer.json" ] && [ ! -f "${project_root}/vendor/autoload.php" ]; then
        composer install --working-dir="${project_root}" --prefer-dist --no-interaction
    fi
    exit 0
fi

tmp_dir="$(mktemp -d)"

cleanup() {
    rm -rf "${tmp_dir}"
}

trap cleanup EXIT INT TERM

echo "Baixando a estrutura base do Laravel..."
composer create-project --prefer-dist --no-interaction laravel/laravel:^12.0 "${tmp_dir}"

echo "Copiando arquivos do Laravel para o projeto..."
rsync -a \
    --exclude=".docker" \
    --exclude=".env" \
    --exclude=".env.example" \
    --exclude=".env.testing" \
    --exclude=".git" \
    --exclude=".gitignore" \
    --exclude="docker-compose.yml" \
    --exclude="Dockerfile" \
    --exclude="README.md" \
    "${tmp_dir}/" "${project_root}/"

mkdir -p \
    "${project_root}/bootstrap/cache" \
    "${project_root}/database" \
    "${project_root}/storage/framework/cache/data" \
    "${project_root}/storage/framework/sessions" \
    "${project_root}/storage/framework/testing" \
    "${project_root}/storage/framework/views" \
    "${project_root}/storage/logs"

touch \
    "${project_root}/database/database.sqlite" \
    "${project_root}/database/database_test.sqlite"

chmod -R ug+rwX \
    "${project_root}/bootstrap/cache" \
    "${project_root}/database" \
    "${project_root}/storage"

echo "Laravel instalado com sucesso."
echo "Proximo passo: cp .env.example .env && docker compose run --rm app php artisan key:generate"
