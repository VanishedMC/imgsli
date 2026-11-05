FROM php:8.4-fpm-alpine

# Production image; local development builds the same file with
# COMPOSER_FLAGS="" (see docker-compose.override.yml) to get the dev
# dependencies (PHPUnit, Mockery, …) for the in-container test runner.
ARG COMPOSER_FLAGS=--no-dev

# mbstring, xml, curl and opcache ship prebuilt in the base image; only the
# missing extensions are compiled from the bundled source. Build deps go in a
# virtual group so they can be removed without orphaning the runtime libs
# (libpng/libzip/…) the extensions link against.
#
# GD: PHP 8.4 compiles the bundled libgd with every format OFF by default;
# --with-jpeg opts into libjpeg (the tests generate JPEGs via imagejpeg).
RUN apk add --no-cache --virtual .build-deps \
        libzip-dev libjpeg-turbo-dev libpng-dev freetype-dev \
        $PHPIZE_DEPS \
    && apk add --no-cache nginx supervisor logrotate dcron libzip libjpeg-turbo libpng freetype \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd bcmath \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install ${COMPOSER_FLAGS} --no-scripts --no-interaction --prefer-dist \
    && composer clear-cache

COPY . .
RUN chown -R www-data:www-data /var/www/html

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/nginx/supervisord.conf
COPY docker/logrotate.conf /etc/logrotate.d/nginx
# Upload-limit defaults (the entrypoint rewrites this from the UPLOAD_* env
# at container start; defaults mirror the entrypoint's: 8 images x 10 MiB).
# Shipped pre-rendered so containers that skip the entrypoint (the compose
# test runner) still get valid values instead of the __*_MAX__ placeholders.
RUN printf 'upload_max_filesize = 12M\npost_max_size = 89M\n' \
    > /usr/local/etc/php/conf.d/99-imgsli-limits.ini
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/nginx/supervisord.conf"]
