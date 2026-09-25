# syntax=docker/dockerfile:1

# One image, three roles, chosen by the container's command:
#
#   web        the dashboard and API (FrankenPHP), and the migrations
#   reverb     the WebSocket server your applications connect to
#   pulse      `pulse:check`, which records connection metrics every 15 seconds
#   scheduler  `schedule:work`, which removes old audit log entries daily
#
# They share one filesystem, so the dashboard and the server it manages can
# never disagree about what the code says.

ARG FRANKENPHP_TAG=1-php8.4-alpine
ARG NODE_TAG=22-bookworm-slim

# --- base -------------------------------------------------------------------
# pdo_mysql for anyone storing apps in MySQL/MariaDB; SQLite is compiled in.
# pcntl lets reverb:start and pulse:check stop cleanly on SIGTERM. uv is the
# libuv event loop from the production tuning guide: ReactPHP picks it up on
# its own and it lifts the select() loop's 1,024-connection ceiling.
FROM dunglas/frankenphp:${FRANKENPHP_TAG} AS base

RUN install-php-extensions pdo_mysql opcache zip pcntl uv \
    && apk add --no-cache curl

WORKDIR /app

# --- composer dependencies --------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache git unzip

# Dependencies first, so a code-only change does not re-resolve the tree.
COPY composer.json composer.lock ./

# `--prefer-install=auto` keeps dist archives as the fast path but lets a
# package that will not download fall back to a git clone, so one 504 from
# api.github.com does not fail the image. COMPOSER_AUTH, when the builder
# passes it, authenticates those downloads; without it Composer downloads
# anonymously and a plain `docker build` still works.
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH \
    composer install --no-dev --no-interaction --no-progress \
        --prefer-install=auto --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script post-autoload-dump

# --- frontend ---------------------------------------------------------------
FROM node:${NODE_TAG} AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund --ignore-scripts

# app.css @sources the pagination views from vendor, so the CSS build needs
# the composer tree.
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# --- runtime ----------------------------------------------------------------
FROM base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    SOUNDBOARD_DATA_DIR=/var/lib/soundboard

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/entrypoint.sh /usr/local/bin/soundboard-entrypoint

# /data and /config belong to Caddy, /var/lib/soundboard to us. Chowning the
# data dir in the image is what gives a fresh named volume the right owner.
RUN chmod +x /usr/local/bin/soundboard-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
        "${SOUNDBOARD_DATA_DIR}" \
    && chown -R www-data:www-data storage bootstrap/cache "${SOUNDBOARD_DATA_DIR}" /data /config

USER www-data

# 8080 is the dashboard in the `web` role and the WebSocket server in the
# `reverb` role; each container only ever listens on the one.
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["soundboard-entrypoint"]
CMD ["web"]
