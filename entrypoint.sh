#!/bin/sh
set -e
cd /var/www/html
composer install --no-dev --optimize-autoloader
exec php-fpm
