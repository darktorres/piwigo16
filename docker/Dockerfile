FROM php:8.5-rc-apache

RUN apt-get update -qq \
 && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev zlib1g-dev libwebp-dev libfreetype6-dev \
        curl \
 && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
 && docker-php-ext-install mysqli gd exif \
 && a2enmod rewrite \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN php -r "new mysqli(); echo 'mysqli ok\n';" 2>/dev/null || true \
 && php -r "gd_info(); echo 'gd ok\n';" \
 && php -r "exif_read_data(''); echo 'exif ok\n';" 2>/dev/null || true

COPY docker/entrypoint.sh /usr/local/bin/piwigo-entrypoint
RUN chmod +x /usr/local/bin/piwigo-entrypoint
CMD ["/usr/local/bin/piwigo-entrypoint"]
