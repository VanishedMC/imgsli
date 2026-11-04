FROM php:8.4-fpm-alpine

# mbstring, xml, curl and opcache ship prebuilt in the base image; only the
# missing extensions are compiled from the bundled source. Build deps go in a
# virtual group so they can be removed without orphaning the runtime libs
# (libpng/libzip/…) the extensions link against.
RUN apk add --no-cache --virtual .build-deps \
        libzip-dev libjpeg-turbo-dev libpng-dev freetype-dev \
        $PHPIZE_DEPS \
    && apk add --no-cache nginx supervisor logrotate dcron libzip libjpeg-turbo libpng freetype \
    && docker-php-ext-install pdo_mysql zip gd bcmath \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist \
    && composer clear-cache

COPY . .
RUN chown -R www-data:www-data /var/www/html

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/nginx/supervisord.conf
COPY docker/logrotate.conf /etc/logrotate.d/nginx
COPY docker/php-limits.ini /usr/local/etc/php/conf.d/99-imgsli-limits.ini
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/nginx/supervisord.conf"]
