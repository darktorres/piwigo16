#!/bin/bash
set -e
# Create Piwigo runtime directories and make them writable by www-data.
# The bind-mount is owned by the host runner user; we need Apache (www-data)
# to be able to write Smarty cache and the database config file.
mkdir -p /var/www/html/_data/templates_c \
         /var/www/html/_data/cache \
         /var/www/html/_data/combined \
         /var/www/html/_data/i \
         /var/www/html/_data/logs \
         /var/www/html/local/config \
         /var/www/html/local/css
chmod -R a+rwX /var/www/html/_data /var/www/html/local
exec apache2-foreground
