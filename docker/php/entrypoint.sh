#!/bin/sh
set -e

echo "Waiting for MySQL..."
until php -r "new PDO('mysql:host=mysql;port=3306;dbname=fund_transfer', 'app', 'app_pass');" 2>/dev/null; do
    sleep 2
done
echo "MySQL ready."

echo "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Clearing cache..."
php bin/console cache:clear

echo "Starting PHP-FPM..."
exec php-fpm
