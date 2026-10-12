# RoomSync Cloud - PHP 8.3 + Apache
FROM php:8.3-apache

# unzip & git dibutuhkan Composer untuk mengunduh paket
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip git \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Document root = folder public/ (src/, vendor/, cron/ tidak bisa diakses dari web)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf 'ServerTokens Prod\nServerSignature Off\n<Directory ${APACHE_DOCUMENT_ROOT}>\n  Options -Indexes\n</Directory>\n' > /etc/apache2/conf-available/roomsync.conf \
 && a2enconf roomsync \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php=Off\nvariables_order="EGPCS"\n' > "$PHP_INI_DIR/conf.d/roomsync.ini"

WORKDIR /var/www/html

# Install dependency dulu (layer ini di-cache selama composer.json tidak berubah)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize \
 && chown -R www-data:www-data /var/www/html

# Render memberikan port lewat variabel PORT (default 10000). Lokal: 80.
CMD ["sh", "-c", "sed -i \"s/^Listen .*/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:[0-9]*>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
