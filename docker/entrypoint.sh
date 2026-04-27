#!/bin/bash
set -e
# Create Piwigo data directories required on a clean checkout.
mkdir -p /var/www/html/_data/templates_c \
         /var/www/html/_data/cache \
         /var/www/html/_data/combined \
         /var/www/html/_data/i \
         /var/www/html/_data/logs
exec apache2-foreground
