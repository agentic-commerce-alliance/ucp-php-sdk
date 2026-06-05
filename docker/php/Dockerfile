FROM composer:2 AS composer

FROM php:8.2-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS git unzip libsqlite3-dev libicu-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_sqlite bcmath intl mbstring dom xmlwriter \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /workspace
