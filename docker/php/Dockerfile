# docker/php/Dockerfile — production single-container image (nginx + php-fpm via supervisord)
FROM php:8.2-fpm-alpine

# Runtime + build deps. nginx/supervisor run the two processes; gettext provides
# envsubst (used by entrypoint to render the nginx vhost with $PORT). $PHPIZE_DEPS
# is only needed to build the PHP extensions below.
RUN apk add --no-cache bash git curl nginx supervisor gettext libpng-dev oniguruma-dev libzip-dev $PHPIZE_DEPS \
 && docker-php-ext-configure gd \
 && docker-php-ext-install pdo_mysql gd zip opcache \
 && apk del $PHPIZE_DEPS

# Activate the production php.ini: turns display_errors OFF so no PHP
# error/trace ever leaks into an HTTP response. Without this the base image
# ships php.ini-development, so e.g. a DB-down request would emit a full
# PDOException stack trace to the browser (HTTP 200). (expose_php is additionally
# forced Off in conf.d/uploads.ini — the stock production ini leaves it On.)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Raise upload/body limits for the admin DOCX template import. PHP defaults
# (upload_max_filesize=2M, post_max_size=8M) are below nginx client_max_body_size
# (30m); keep both PHP limits at 30M so the DOCX import isn't truncated by PHP
# before nginx even hands off.
COPY docker/php/uploads.ini "$PHP_INI_DIR/conf.d/uploads.ini"

# Stream nginx access/error logs to the container's stdout/stderr so Railway (or
# `docker logs`) captures request/error output. (php-fpm already logs via
# supervisord.) The base nginx package points these at files under /var/log/nginx.
RUN ln -sf /dev/stdout /var/log/nginx/access.log \
 && ln -sf /dev/stderr /var/log/nginx/error.log

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# --- Dependency layer: copy manifests first so `composer install` is cached
#     until composer.json/composer.lock actually change. ---
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# --- Application layer. .dockerignore excludes vendor/, storage/, tests/, .git, etc. ---
COPY . .
# Re-run to wire up the app autoloader now that src/ is present (deps already cached).
RUN composer dump-autoload --optimize --no-dev --no-interaction

# Process manager config + entrypoint.
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Railway injects PORT at runtime; default for local `docker run`.
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
