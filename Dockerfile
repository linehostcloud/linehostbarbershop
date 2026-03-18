FROM composer:2.8 AS composer

FROM php:8.3-fpm-bookworm

ARG APP_UID=1000
ARG APP_GID=1000

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        zip \
        curl \
        ca-certificates \
        rsync \
        netcat-openbsd \
        libfcgi-bin \
        mariadb-client \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libxml2-dev \
        libonig-dev \
        libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && groupadd -o --gid "${APP_GID}" app \
    && useradd -o --uid "${APP_UID}" --gid app --create-home --shell /bin/bash app \
    && mkdir -p /var/www/html /tmp/composer-cache \
    && chown -R app:app /var/www/html /tmp/composer-cache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY .docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/99-app.ini
COPY .docker/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY .docker/bin/entrypoint.sh /usr/local/bin/project-entrypoint
COPY .docker/bin/wait-for-dependencies.sh /usr/local/bin/wait-for-dependencies
COPY .docker/bin/install-laravel.sh /usr/local/bin/install-laravel

RUN chmod +x /usr/local/bin/project-entrypoint /usr/local/bin/wait-for-dependencies /usr/local/bin/install-laravel

WORKDIR /var/www/html
USER app
