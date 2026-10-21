#!/usr/bin/env bash
set -euo pipefail

cd /var/www

if [ ! -f .env ]; then
  cp .env.example .env
fi

if ! grep -q "^APP_KEY=base64" .env && [ -z "${APP_KEY:-}" ]; then
  php artisan key:generate --force
fi

# Only the main API container runs migrations; the scheduler just waits for them.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "Waiting for the database..."
  for i in $(seq 1 30); do
    php artisan db:show >/dev/null 2>&1 && break
    sleep 2
  done

  php artisan migrate --force

  if [ "${SEED_DEMO_DATA:-false}" = "true" ]; then
    php artisan demo:seed-if-empty
  fi
fi

php artisan config:clear >/dev/null
# artisan ran as root above; hand writable dirs back to php-fpm's user.
chown -R www-data:www-data storage bootstrap/cache
exec "$@"
