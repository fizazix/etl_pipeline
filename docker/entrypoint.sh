#!/bin/sh
set -e

echo "Waiting for MySQL..."
until mysqladmin ping -h"$DB_HOST" -u"$DB_USERNAME" -p"$DB_PASSWORD" --skip-ssl --silent 2>/dev/null; do
    sleep 2
done

echo "Waiting for source API..."
until curl -sf "http://source-api:8080/records" > /dev/null 2>&1; do
    sleep 2
done

if [ ! -f .env ]; then
    cp .env.example .env
fi

composer install --no-interaction --prefer-dist --optimize-autoloader

php artisan key:generate --force

php artisan migrate --force

php artisan ingestion:run

exec php artisan serve --host=0.0.0.0 --port=8000
