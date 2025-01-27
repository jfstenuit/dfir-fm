# Base image with PHP-FPM
FROM php:8.2-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# Install dependencies
RUN apk add --no-cache lighttpd git unzip bash \
    && mkdir -p /run/lighttpd

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application code
COPY ../src /var/www/html/src/
COPY ../assets /var/www/html/assets/
COPY ../index.php /var/www/html/
COPY ../composer.json /var/www/html/
COPY ../composer.lock /var/www/html/

# Run composer install to set up dependencies and autoload
RUN composer install --no-dev --optimize-autoloader \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

COPY lighttpd.conf /etc/lighttpd/lighttpd.conf

# Expose HTTP port
EXPOSE 80

# Start lighttpd and PHP-FPM
CMD php-fpm -D && lighttpd -D -f /etc/lighttpd/lighttpd.conf
