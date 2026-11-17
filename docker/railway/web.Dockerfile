# The hosted web service: nginx and PHP-FPM in one container, because a Railway
# service is one container and the local split (nginx -> php:9000) would only
# add a private-network hop. The same image runs the importer service, with a
# different start command (bin/railway-import).
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        curl \
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
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-poc.ini
COPY docker/railway/nginx.conf /etc/nginx/conf.d/default.conf.template

WORKDIR /app

# Dependencies first, so a code change does not reinstall them.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize

CMD ["bash", "docker/railway/start-web.sh"]
