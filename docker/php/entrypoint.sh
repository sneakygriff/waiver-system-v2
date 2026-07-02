#!/usr/bin/env bash
# [railway-prod] Container boot: prepare runtime dirs, render nginx config with
# the Railway-injected $PORT, materialize config/config.php from env, then hand
# off to supervisord (php-fpm + nginx). No build steps here — composer install
# happens at image-build time.
set -e
cd /var/www/html

# storage/ is a mounted volume (transient artifacts + dompdf tmp); ensure the
# subdirs exist and php-fpm's www-data can write them.
mkdir -p storage/signatures storage/artifacts
chown -R www-data:www-data storage || true

# Render nginx vhost with the runtime PORT. Only ${PORT} is substituted so the
# nginx $uri / $document_root variables in the template survive untouched.
: "${PORT:=8080}"
export PORT
envsubst '${PORT}' < /var/www/html/docker/nginx/default.conf.template \
  > /etc/nginx/http.d/default.conf

# Materialize config/config.php from the committed env-driven template. The four
# public entrypoints always `require config/config.php`; the fail-closed sentinel
# (Utils::assertNoPlaceholderSecrets) then runs against these real env values.
cp /var/www/html/config/config.env.php /var/www/html/config/config.php

exec supervisord -c /etc/supervisord.conf
