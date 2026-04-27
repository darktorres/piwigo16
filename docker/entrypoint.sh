#!/bin/bash
set -e
# Create Piwigo data directories and set write permissions for www-data.
mkdir -p /var/www/html/_data/templates_c \
         /var/www/html/_data/cache \
         /var/www/html/_data/combined \
         /var/www/html/_data/i \
         /var/www/html/_data/logs
chown -R www-data:www-data /var/www/html/_data
exec apache2-foreground
