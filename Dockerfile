FROM composer:2 AS deps
WORKDIR /build
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

FROM php:8.5-apache

# DB drivers (sqlite3/pdo_sqlite are built in) and tools TorrentFlux shells out to.
# gd is built against libpng-dev; keep the libpng runtime so gd loads at run time.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        net-tools procps wget unzip cksfv mktorrent libpng-dev \
    && docker-php-ext-configure gd \
    && docker-php-ext-install -j"$(nproc)" mysqli gd \
    && apt-get install -y --no-install-recommends libpng16-16 \
    && apt-get purge -y libpng-dev \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# app
COPY html/ /var/www/html/
COPY --from=deps /build/vendor/ /var/www/vendor/

# writable paths: config (setup writes it), db (sqlite), downloads
RUN mkdir -p /downloads /var/www/db \
    && chown -R www-data:www-data /var/www/html/inc/config /var/www/db /downloads

# Make www-data a member of gid 1000 so Apache workers (which re-initialise
# supplementary groups from /etc/group when dropping privileges) can write to a
# bind-mounted downloads dir owned by the host's uid/gid 1000 (the media stack).
RUN (getent group 1000 >/dev/null || groupadd -g 1000 hostmedia) \
    && usermod -aG 1000 www-data

VOLUME ["/downloads", "/var/www/db"]

EXPOSE 80
