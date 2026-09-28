#!/bin/sh
set -e

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

until php -r "new PDO('pgsql:host=postgres;port=5432;dbname=komkom', getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" > /dev/null 2>&1; do
  echo "En attente de PostgreSQL..."
  sleep 2
done

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
