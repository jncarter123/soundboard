#!/bin/sh
#
# Three roles out of one image:
#
#   web        FrankenPHP over public/, plus the migrations.
#   reverb     `reverb:start`, the WebSocket server your applications connect to.
#   pulse      `pulse:check`, which records each app's connection count.
#   scheduler  `schedule:work`: daily cleanup of old audit log entries.
#   reverb-lb  Caddy in front of several Reverb replicas (compose.scaling.yaml).
#
# The web container owns the migrations and the key so the three never race;
# the others wait for it (compose holds them back until /up answers).

set -e

role="${1:-web}"

# The load balancer runs no Laravel code: no key, database, or caches.
if [ "${role}" = "reverb-lb" ]; then
    exec frankenphp run --config /app/docker/Caddyfile.reverb-lb
fi
data_dir="${SOUNDBOARD_DATA_DIR:-/var/lib/soundboard}"
key_file="${data_dir}/app_key"

mkdir -p "${data_dir}"

# --- APP_KEY ---------------------------------------------------------------
# It encrypts every stored Reverb app secret. Losing it does not lock you out
# of the dashboard, it makes every app's secret unreadable and every client
# using them fail to connect, so it is kept on the volume and loudly announced
# the once.
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f "${key_file}" ] && [ "${role}" != "web" ]; then
        echo "soundboard: waiting for the web container to write ${key_file}" >&2
        waited=0
        while [ ! -f "${key_file}" ] && [ "${waited}" -lt 60 ]; do
            sleep 1
            waited=$((waited + 1))
        done
    fi

    if [ ! -f "${key_file}" ]; then
        php artisan key:generate --show > "${key_file}"
        chmod 600 "${key_file}"
        echo "soundboard: generated an APP_KEY in ${key_file}." >&2
        echo "soundboard: back up that volume — without this key the stored app secrets cannot be decrypted." >&2
    fi

    APP_KEY="$(cat "${key_file}")"
    export APP_KEY
fi

# --- the app's own store ---------------------------------------------------
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    : "${DB_DATABASE:=${data_dir}/database.sqlite}"
    export DB_DATABASE

    if [ ! -f "${DB_DATABASE}" ]; then
        touch "${DB_DATABASE}"
        chmod 600 "${DB_DATABASE}"
    fi
fi

# --- caches ----------------------------------------------------------------
# Built here rather than at image build time: the env is only complete now.
php artisan config:cache
php artisan view:cache

# A missing route cache is a slower app, not a broken one, so it is not fatal.
if ! php artisan route:cache; then
    echo "soundboard: route:cache failed, continuing without a route cache" >&2
    php artisan route:clear
fi

case "${role}" in
    web)
        php artisan migrate --force
        exec frankenphp run --config /app/docker/Caddyfile
        ;;
    reverb)
        # Listens on every interface inside the container; compose decides
        # what, if anything, reaches it from outside.
        exec php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    pulse)
        exec php artisan pulse:check
        ;;
    scheduler)
        # A schedule mutex stranded by a kill would hold a task off until it
        # expires. Nothing of ours is running yet, so clearing is always safe.
        php artisan schedule:clear-cache \
            || echo "soundboard: schedule:clear-cache failed; a stale mutex may delay the next run" >&2
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
