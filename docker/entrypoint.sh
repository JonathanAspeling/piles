#!/usr/bin/env bash
set -euo pipefail

# The SQLite file lives on its own volume at /data, deliberately outside the
# app's database/ directory: a named volume mounted over /app/database would
# shadow the migrations and seeders shipped in the image with whatever copy the
# volume was first initialised from, silently freezing them at that version.
DB_PATH="${DB_DATABASE:-/data/database.sqlite}"

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    mkdir -p "$(dirname "$DB_PATH")"
    if [ ! -f "$DB_PATH" ]; then
        touch "$DB_PATH"
        chown www-data:www-data "$DB_PATH"
    fi
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
    php artisan db:seed --class=CardSeeder --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

exec "$@"
