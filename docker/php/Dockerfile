# docker/php/Dockerfile
FROM php:8.2-fpm-alpine

RUN apk add --no-cache bash git curl libpng-dev oniguruma-dev libzip-dev $PHPIZE_DEPS  && docker-php-ext-configure gd  && docker-php-ext-install pdo_mysql gd zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm", "-F"]
