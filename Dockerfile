FROM composer:2 AS deps
WORKDIR /build
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

FROM php:8.5-apache

# DB drivers (sqlite3/pdo_sqlite are built in) and tools TorrentFlux shells out to
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        net-tools procps wget unzip cksfv libpng-dev \
    && docker-php-ext-configure gd \
    && docker-php-ext-install -j"$(nproc)" mysqli gd \
    && apt-get purge -y libpng-dev \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# app
COPY html/ /var/www/html/
COPY --from=deps /build/vendor/ /var/www/vendor/

# writable paths: config (setup writes it), db (sqlite), downloads
RUN mkdir -p /downloads /var/www/db \
    && chown -R www-data:www-data /var/www/html/inc/config /var/www/db /downloads

VOLUME ["/downloads", "/var/www/db"]

EXPOSE 80
