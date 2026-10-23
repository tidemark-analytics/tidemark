#!/bin/sh
# Points the app's writable paths at the /data volume, creates the settings file on first run, and starts the scheduler.
set -e

# As root: give /data (and the few writable spots in /app) to PUID:PGID, then run this script again as that user.
# Unraid uses 99:100 (nobody:users). Starting the container as a non-root user (docker --user) skips this.
if [ "$(id -u)" = "0" ]; then
    PUID="${PUID:-1000}"
    PGID="${PGID:-1000}"
    mkdir -p /data /config
    [ "$(stat -c %u:%g /data)" = "$PUID:$PGID" ] || chown -R "$PUID:$PGID" /data
    chown -R "$PUID:$PGID" /config /app/bootstrap/cache
    chown "$PUID:$PGID" /app /app/database
    exec su-exec "$PUID:$PGID" "$0" "$@"
fi

DATA=/data
mkdir -p "$DATA/storage/app/public" "$DATA/storage/framework/cache/data" "$DATA/storage/framework/sessions" "$DATA/storage/framework/views" "$DATA/storage/logs"
ln -sfn "$DATA/storage" /app/storage

if [ ! -f "$DATA/.env" ]; then
    cp /app/.env.example "$DATA/.env"
    KEY="base64:$(head -c 32 /dev/urandom | base64)"
    sed -i "s#^APP_KEY=.*#APP_KEY=${KEY}#" "$DATA/.env"
    sed -i "s#^LOG_STACK=.*#LOG_STACK=single#" "$DATA/.env"
fi
ln -sf "$DATA/.env" /app/.env

# the web installer's SQLite option writes database/database.sqlite, which lives in the volume
[ -f "$DATA/database.sqlite" ] || : > "$DATA/database.sqlite"
ln -sf "$DATA/database.sqlite" /app/database/database.sqlite

rm -f /app/bootstrap/cache/*.php

# the scheduler: prunes old events, drains the ingest queue and looks for new versions
( while true; do php /app/artisan schedule:run >/dev/null 2>&1 || true; sleep 60; done ) &

exec "$@"
