# =============================================================================
# Orbit — PHP 8.5 FPM application image
# =============================================================================
# Runs as a NON-ROOT user from day one. The uid/gid are the host `orbit` system
# user's, so everything the container writes through the bind mount (vendor/,
# storage/, public/build/) keeps sane ownership and artisan never has to be run
# as root on the host — that is what poisoned Scribly's cache files.
#
# The defaults below are the real ids on this box; docker-compose.yml passes
# them explicitly as build args anyway, so a rebuild elsewhere is one flag.
# =============================================================================
FROM php:8.5-fpm-alpine

ARG APP_UID=115
ARG APP_GID=119
ARG APP_USER=app

# ---- PHP extensions --------------------------------------------------------
# pdo_pgsql : Postgres 18
# redis     : queue + cache driver
# pcntl     : HARD requirement of laravel/horizon. Horizon's master process
# posix       forks and signals its supervisors; composer refuses to install it
#             without both (`ext-pcntl * is missing from your platform`), and
#             pcntl is not in the stock php:8.5-fpm-alpine build.
# bcmath    : fare arithmetic. Prices are money and the deal score is a
#             percentile over them; neither wants float rounding.
# intl      : dates and money formatted for Europe/Amsterdam, and IATA/city
#             names that are not ASCII.
#
# DELIBERATELY ABSENT, unlike the health-tracker image this is modelled on:
#   gd  — Orbit uploads no images. Flags are CSS gradients (see design/README)
#         and the globe textures are static files.
#   zip — nothing here parses an .xlsx.
# Both cost a build-dep and a runtime library each; an extension that is not
# installed is attack surface that does not need patching.
#
# NB: opcache is compiled into the official php:8.5 images already — running
#     `docker-php-ext-install opcache` against them FAILS, so it is not listed.
RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers postgresql-dev icu-dev; \
    apk add --no-cache postgresql-client icu-libs libpq bash; \
    docker-php-ext-install pdo_pgsql bcmath intl pcntl posix; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---- Non-root app user -----------------------------------------------------
RUN set -eux; \
    addgroup -g "${APP_GID}" "${APP_USER}"; \
    adduser -D -u "${APP_UID}" -G "${APP_USER}" -h /home/"${APP_USER}" "${APP_USER}"; \
    mkdir -p /home/"${APP_USER}"/.composer; \
    chown -R "${APP_UID}:${APP_GID}" /home/"${APP_USER}"

# php-fpm's stock pool wants to setuid to www-data. The container already runs
# as a non-root user, so those directives are neutered instead of ignored-with-
# a-warning on every boot.
RUN set -eux; \
    sed -ri 's/^user = www-data/;user = www-data/; s/^group = www-data/;group = www-data/' \
        /usr/local/etc/php-fpm.d/www.conf

COPY php.ini      /usr/local/etc/php/conf.d/99-orbit.ini
COPY zz-pool.conf /usr/local/etc/php-fpm.d/zz-orbit.conf

ENV COMPOSER_HOME=/home/app/.composer
ENV COMPOSER_ALLOW_SUPERUSER=0

WORKDIR /var/www/html

USER ${APP_USER}

EXPOSE 9000
CMD ["php-fpm"]
