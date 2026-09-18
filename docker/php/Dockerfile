FROM php:8.3-fpm-bookworm

# Debian base rather than alpine: pdo_mysql/zip/opcache build without musl
# workarounds, and the image is only built once for a POC anyway.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        opcache \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-poc.ini

WORKDIR /app
