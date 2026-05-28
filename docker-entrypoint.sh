#!/bin/sh
set -e

# Default PORT
: "${PORT:=8080}"

cd /var/www/html

# If .env does not exist, create it from example
if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

# Substitute environment variables inside .env when placeholders remain
if [ -f .env ] && command -v envsubst >/dev/null 2>&1; then
  if grep -q '\${' .env; then
    envsubst < .env > .env.tmp && mv .env.tmp .env
  fi
fi

# Ensure composer autoload is up to date (in case volumes override)
composer dump-autoload -o || true

# Run migrations only if DB_HOST appears set and doesn't contain unreplaced placeholders
if [ -n "$DB_HOST" ] && ! echo "$DB_HOST" | grep -q '\${'; then
  php artisan migrate --force || true
fi

# Start the built-in PHP server (Railway sets $PORT)
exec "$@"
