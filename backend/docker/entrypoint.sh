#!/usr/bin/env bash
set -euo pipefail

cd /var/www

case "${APP_ENV:-production}" in local|testing) DEV=1 ;; *) DEV=0 ;; esac

if [ "$DEV" = "0" ]; then
  # Refuse to start with development settings (debug on, OTP echoed, sqlite, ...).
  php artisan env:check
else
  # Local development convenience: create .env and a key on first run.
  [ -f .env ] || cp .env.example .env
  if ! grep -q "^APP_KEY=base64" .env && [ -z "${APP_KEY:-}" ]; then
    php artisan key:generate --force
  fi
fi

# Only the main API container runs migrations; the scheduler and queue worker just reuse the image.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "Waiting for the database..."
  for i in $(seq 1 30); do
    php artisan db:show >/dev/null 2>&1 && break
    sleep 2
  done

  php artisan migrate --force

  if [ "${SEED_DEMO_DATA:-false}" = "true" ]; then
    if [ "$DEV" = "0" ]; then
      echo "Refusing to seed demo accounts (known passwords) in production." >&2
      exit 1
    fi
    php -d memory_limit=512M artisan demo:seed-if-empty
  fi
fi

if [ "$DEV" = "0" ]; then
  php artisan config:cache
  php artisan route:cache
  php artisan event:cache
else
  php artisan config:clear >/dev/null
fi

exec "$@"
