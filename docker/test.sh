#!/bin/sh
set -e

composer install --no-interaction

php docker/create-test-db.php

php artisan migrate --force
php artisan test "$@"
