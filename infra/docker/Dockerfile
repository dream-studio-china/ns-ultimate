ARG NODE_IMAGE=node:22-alpine
ARG PHP_IMAGE=php:8.5-fpm-alpine
ARG NGINX_IMAGE=nginx:stable-alpine
FROM ${NODE_IMAGE} AS admin-build

WORKDIR /workspace
COPY core/crud-admin/package.json core/crud-admin/package-lock.json /workspace/core/crud-admin/
RUN npm ci --prefix core/crud-admin
COPY . /workspace
RUN npm run build --prefix core/crud-admin -- --config ../../integration/admin/vite.config.ts

FROM ${PHP_IMAGE} AS php-base

RUN apk add --no-cache icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl pdo_mysql zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM php-base AS app

WORKDIR /srv/app
COPY core/crud-skeleton/composer.json core/crud-skeleton/composer.lock core/crud-skeleton/symfony.lock /srv/app/core/crud-skeleton/
RUN composer install \
    --working-dir=/srv/app/core/crud-skeleton \
    --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --no-autoloader

COPY . /srv/app
RUN composer dump-autoload \
    --working-dir=/srv/app/core/crud-skeleton \
    --no-dev --no-scripts --no-interaction --optimize \
    && mkdir -p /srv/app/var/cache/backend /srv/app/var/log/backend /srv/app/var/data \
        /srv/app/core/crud-skeleton/public/uploads \
    && chown -R www-data:www-data /srv/app/var /srv/app/core/crud-skeleton/public/uploads

FROM php-base AS php-dev

RUN apk add --no-cache sqlite-libs \
    && apk add --no-cache --virtual .sqlite-build-deps sqlite-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
    && apk del .sqlite-build-deps

WORKDIR /srv/app
COPY core/crud-skeleton/composer.json core/crud-skeleton/composer.lock core/crud-skeleton/symfony.lock /srv/app/core/crud-skeleton/
RUN composer install \
    --working-dir=/srv/app/core/crud-skeleton \
    --no-scripts --no-interaction --no-progress --prefer-dist

COPY . /srv/app
RUN composer dump-autoload \
    --working-dir=/srv/app/core/crud-skeleton \
    --no-scripts --no-interaction --optimize

CMD ["php", "-S", "0.0.0.0:8000", "-t", "integration/backend/public", "integration/backend/public/index.php"]

FROM ${NGINX_IMAGE} AS nginx
COPY infra/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /srv/app/integration/backend/public /srv/app/integration/backend/public
COPY --from=admin-build /workspace/dist/admin /srv/app/dist/admin

EXPOSE 80
