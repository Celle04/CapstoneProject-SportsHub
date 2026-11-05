#!/bin/sh
set -e
PORT="${PORT:-8080}"
case "$PORT" in
    ''|*[!0-9]*)
        echo "PORT must be an integer between 1 and 65535." >&2
        exit 1
        ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT must be an integer between 1 and 65535." >&2
    exit 1
fi

RUN_MIGRATIONS="${RUN_MIGRATIONS:-true}"
case "$RUN_MIGRATIONS" in
    true|false) ;;
    *)
        echo "RUN_MIGRATIONS must be either true or false." >&2
        exit 1
        ;;
esac

sed -ri "s/^Listen [0-9]+$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
grep -Fqx "Listen ${PORT}" /etc/apache2/ports.conf
grep -Fq "<VirtualHost *:${PORT}>" /etc/apache2/sites-available/000-default.conf
apache2ctl configtest

mkdir -p storage/app/private storage/app/public \
         storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs bootstrap/cache
php artisan storage:link --force
php artisan view:cache
if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
fi
chown -R www-data:www-data storage bootstrap/cache
exec apache2-foreground
