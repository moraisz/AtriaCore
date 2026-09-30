# PHP image used by `composer ci`: the same PHP version and extensions as
# .github/workflows/quality.yml, so local runs match CI.
ARG PHP_VERSION=8.4
ARG COMPOSER_VERSION=2

FROM composer:${COMPOSER_VERSION} AS composer

FROM php:${PHP_VERSION}-cli

# curl, sqlite3, mbstring and openssl ship with the official image.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pgsql mysqli

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /app
