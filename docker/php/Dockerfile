ARG PHP_VERSION=8.2

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS git unzip libsqlite3-dev libpq-dev libicu-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_sqlite pdo_mysql pdo_pgsql bcmath intl mbstring dom xmlwriter \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /workspace

# The repository is bind-mounted from the host and therefore owned by a different user than the
# container's root, so git aborts with "detected dubious ownership". Mark every directory as safe
# so tooling that shells out to git works — notably roave/backward-compatibility-check, which both
# resolves the baseline tag (`git rev-parse <tag>`) and clones the worktree (`git clone /workspace`,
# tripping the check on /workspace/.git). A wildcard is appropriate for this throwaway CI image.
RUN git config --global --add safe.directory '*'
