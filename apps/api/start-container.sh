#!/bin/sh
set -eu

# Railpack starts the container in /app. Never create an ephemeral photo fallback.
[ -f artisan ] || { echo 'Start from the API application directory.' >&2; exit 1; }
sh verify-entrypoint.sh
: "${APP_KEY:?APP_KEY must be configured and preserved across deployments.}"
: "${DB_URL:?DB_URL must reference the staging PostgreSQL service.}"
[ "${DB_CONNECTION:-}" = pgsql ] || { echo 'DB_CONNECTION must be pgsql.' >&2; exit 1; }
photo_directory="$(pwd)/storage/app/private"
if [ "${RAILWAY_VOLUME_MOUNT_PATH:-}" != "$photo_directory" ] || [ ! -d "$photo_directory" ] || [ ! -w "$photo_directory" ]; then
    echo 'A writable Railway photo volume must be mounted at /app/storage/app/private.' >&2
    exit 1
fi

# Migrations run in Railway pre-deploy, never on restarts. Preserve shared cache.
php artisan config:clear --no-interaction
php artisan niwadu:check-deployment-database --no-interaction
php artisan package:discover --no-interaction
php artisan config:cache --no-interaction
php artisan event:cache --no-interaction
php artisan route:cache --no-interaction

exec docker-php-entrypoint --config /Caddyfile --adapter caddyfile
