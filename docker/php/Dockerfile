# docker/php/Dockerfile — production single-container image (nginx + php-fpm via supervisord)
FROM php:8.2-fpm-alpine

# Runtime + build deps. nginx/supervisor run the two processes; gettext provides
# envsubst (used by entrypoint to render the nginx vhost with $PORT). $PHPIZE_DEPS
# is only needed to build the PHP extensions below.
RUN apk add --no-cache bash git curl nginx supervisor gettext libpng-dev oniguruma-dev libzip-dev $PHPIZE_DEPS \
 && docker-php-ext-configure gd \
 && docker-php-ext-install pdo_mysql gd zip \
 && apk del $PHPIZE_DEPS

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
