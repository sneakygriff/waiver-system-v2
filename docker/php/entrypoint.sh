#!/usr/bin/env bash
set -e
cd /var/www/html

mkdir -p storage/signatures storage/artifacts
chown -R www-data:www-data storage || true

if [ -f composer.json ] && [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

exec "$@"
