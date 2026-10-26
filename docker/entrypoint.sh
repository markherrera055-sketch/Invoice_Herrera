#!/bin/sh
set -e

cd /var/www/html

# .env is mounted from the host; create one from the example if it is missing
if [ ! -f .env ]; then
    cp .env.example .env
fi

echo "Waiting for database at ${DB_HOST}:${DB_PORT}..."
until mysqladmin ping -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" --silent; do
    sleep 2
done

if ! grep -q '^APP_KEY=base64:' .env && [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

# Remove caches generated on the host (they may contain host paths)
rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover --ansi
php artisan storage:link --force || true

php artisan migrate --force
php artisan db:seed --force

# Create the first admin account on a fresh database
ACCOUNTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" -N -s -e "SELECT COUNT(*) FROM accounts" "$DB_DATABASE")
if [ "$ACCOUNTS" = "0" ] && [ -n "$IN_USER_EMAIL" ]; then
    php artisan ninja:create-account --email="$IN_USER_EMAIL" --password="$IN_PASSWORD"
fi

php artisan optimize:clear
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
