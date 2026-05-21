FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
    git curl libzip-dev icu-dev oniguruma-dev \
    linux-headers autoconf make g++

RUN docker-php-ext-install pdo_mysql intl zip bcmath opcache \
    && docker-php-ext-enable opcache

RUN pecl install redis && docker-php-ext-enable redis \
    && apk del autoconf make g++

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=1'; \
} > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

FROM base AS development

RUN apk add --no-cache autoconf make g++ \
    && pecl install xdebug && docker-php-ext-enable xdebug \
    && apk del autoconf make g++

RUN { \
    echo 'xdebug.mode=coverage'; \
    echo 'xdebug.start_with_request=yes'; \
    echo 'xdebug.client_host=host.docker.internal'; \
    echo 'xdebug.client_port=9003'; \
} > /usr/local/etc/php/conf.d/xdebug.ini

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
