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

# Fail fast on a volume permission mismatch: prove www-data can actually write
# into storage/artifacts NOW, at boot, rather than discovering it only when the
# first guest submits and PDF generation silently fails. (dompdf writes here.)
if ! su -s /bin/sh -c 'touch storage/artifacts/.write_test && rm -f storage/artifacts/.write_test' www-data; then
  echo "FATAL: storage/artifacts is not writable by www-data. Check the volume mount/permissions." >&2
  exit 1
fi

# Render nginx vhost with the runtime PORT. Only ${PORT} is substituted so the
# nginx $uri / $document_root variables in the template survive untouched.
: "${PORT:=8080}"
export PORT
envsubst '${PORT}' < /var/www/html/docker/nginx/default.conf.template \
  > /etc/nginx/http.d/default.conf

# Materialize config/config.php from the committed env-driven template. The four
# public entrypoints always `require config/config.php`; the fail-closed sentinel
# (Utils::assertNoPlaceholderSecrets) then runs against these real env values.
# Guard the copy: on Railway the image has no config/config.php so this always
# runs, but the local-dev compose bind-mounts the repo (./:/var/www/html) with
# the developer's real gitignored config/config.php — don't clobber it.
[ -f /var/www/html/config/config.php ] \
  || cp /var/www/html/config/config.env.php /var/www/html/config/config.php

exec supervisord -c /etc/supervisord.conf
