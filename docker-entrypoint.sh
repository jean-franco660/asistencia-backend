#!/bin/sh
set -e

# Default PORT
: "${PORT:=8080}"

cd /var/www/html

# If .env does not exist, create it from example and substitute env vars
if [ ! -f .env ]; then
  if [ -f .env.example ]; then
    cp .env.example .env
    if command -v envsubst >/dev/null 2>&1; then
      envsubst < .env > .env.tmp && mv .env.tmp .env
    fi
  fi
fi

# Ensure composer autoload is up to date (in case volumes override)
composer dump-autoload -o || true

# Run migrations only if DB_HOST appears set and doesn't contain unreplaced placeholders
if [ -n "$DB_HOST" ] && ! echo "$DB_HOST" | grep -q "\$\{"; then
  php artisan migrate --force || true
fi

# Start the built-in PHP server (Railway sets $PORT)
exec "$@"
