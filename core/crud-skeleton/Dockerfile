# CRUD Skeleton — PHP Application
# Targets: ~90 MB final image (Alpine + PHP 8.4 + extensions + app)

FROM php:8.4-fpm-alpine

# PHP extensions
RUN apk add --no-cache \
    icu-libs \
    libzip \
    openssl \
    && apk add --no-cache --virtual .build-deps \
    icu-dev \
    libzip-dev \
    linux-headers \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \
    intl \
    zip \
    opcache \
    && apk del .build-deps

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App
WORKDIR /var/www/html

# Dependencies first (cache layer)
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --no-progress --optimize-autoloader \
    && composer clear-cache

# Application code
COPY . ./
RUN composer dump-autoload --no-dev --optimize --no-interaction

# Writable directories
RUN mkdir -p var/jwt var/cache var/log \
    && chown -R www-data:www-data var

# Entrypoint: creates persistent dev JWT keys and validates prod keys
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
