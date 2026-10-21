# syntax=docker/dockerfile:1

# ---- 1. PHP dependencies (production only: no dev packages) ---------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts --ignore-platform-reqs

# ---- 2. API runtime (php-fpm, non-root) -------------------------------------------------------
FROM php:8.3-fpm-alpine AS app
RUN apk add --no-cache icu-dev libzip-dev oniguruma-dev bash fcgi \
 && docker-php-ext-install pdo_mysql intl zip bcmath opcache pcntl

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www
# The dev entrypoint writes .env here, so the directory itself must belong to the app user.
RUN chown www-data:www-data /var/www
COPY --from=vendor --chown=www-data:www-data /app ./
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/framework/testing storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod +x docker/entrypoint.sh

USER www-data
EXPOSE 9000
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]

# ---- 3. Web server for the API (non-root nginx, serves public/ and proxies PHP) ---------------
FROM nginxinc/nginx-unprivileged:1.27-alpine AS web
COPY docker/nginx.prod.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/public /var/www/public
EXPOSE 8080
