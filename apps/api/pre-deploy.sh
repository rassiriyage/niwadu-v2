#!/bin/sh
set -eu

# Railway runs this without the photo volume. Refuse Laravel's SQLite fallback.
: "${APP_KEY:?APP_KEY must be configured and preserved across deployments.}"
: "${DB_URL:?DB_URL must reference the staging PostgreSQL service.}"
[ "${DB_CONNECTION:-}" = pgsql ] || { echo 'DB_CONNECTION must be pgsql.' >&2; exit 1; }
php artisan config:clear --no-interaction
php artisan niwadu:check-deployment-database --no-interaction
php artisan migrate --force --no-interaction
