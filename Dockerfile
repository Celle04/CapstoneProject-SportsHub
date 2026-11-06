# Build the Vite and Tailwind assets with Node.js 22.
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json ./
RUN npm install --no-audit --no-fund

COPY . .
RUN npm run build

# Install PHP dependencies against the same PHP version and extensions as
# the Apache runtime.
FROM php:8.3-apache AS php-base

WORKDIR /var/www/html

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        mbstring \
        opcache \
        pdo_mysql \
        zip; \
    rm -rf /var/lib/apt/lists/*

# The official PHP Apache image selects mpm_prefork for mod_php. Keep that
# image default; only enable the Laravel-required non-MPM modules here.
RUN set -eux; \
    a2enmod rewrite headers; \
    printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/servername.conf; \
    a2enconf servername; \
    apache2ctl configtest

# Build vendor dependencies with the PHP runtime's platform requirements.
FROM php-base AS dependencies

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

# Run Laravel on Apache, serving only the public directory.
FROM php-base AS runtime

WORKDIR /var/www/html

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY . .
COPY --from=dependencies /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN set -eux; \
    mkdir -p storage/app/private \
             storage/app/public \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R 775 storage bootstrap/cache; \
    sed -i 's/\r$//' /usr/local/bin/entrypoint; \
    chmod +x /usr/local/bin/entrypoint; \
    apache2ctl -M || true; \
    ls -la /etc/apache2/mods-enabled/; \
    ls -la /etc/apache2/mods-available/; \
    grep -R "LoadModule mpm_" /etc/apache2/ || true; \
    cat /etc/apache2/apache2.conf; \
    cat /etc/apache2/envvars; \
    MPM_MODULES="$(apache2ctl -M 2>&1 | grep -Eo 'mpm_(event|worker|prefork)_module' || true)"; \
    printf 'Loaded MPM modules: %s\n' "$MPM_MODULES"; \
    test "$MPM_MODULES" = "mpm_prefork_module"; \
    apache2ctl configtest

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint"]
