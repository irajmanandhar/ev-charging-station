# syntax=docker/dockerfile:1
FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev oniguruma-dev sqlite-dev bash \
 && docker-php-ext-install pdo_mysql pdo_sqlite intl zip bcmath opcache pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Install dependencies first so code changes don't bust this layer.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/framework/testing storage/logs bootstrap/cache \
 && composer dump-autoload --optimize \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
