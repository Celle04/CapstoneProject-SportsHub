#!/bin/sh
set -e
PORT="${PORT:-10000}"
sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
mkdir -p storage/app/private storage/app/public \
         storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs bootstrap/cache
php artisan storage:link --force
# route:cache is NOT used: routes/web.php contains closures. config:cache skipped for safety.
php artisan view:cache
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi
chown -R www-data:www-data storage bootstrap/cache
exec apache2-foreground
