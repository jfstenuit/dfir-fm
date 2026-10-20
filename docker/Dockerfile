# DFIR FM: PHP-FPM and lighttpd in one container, listening on 8080.
# Build from the repository root (docker-compose.yml sets the context):
#   docker build -f docker/Dockerfile .
FROM php:8.4-fpm-alpine

WORKDIR /var/www/html

# lighttpd, bash for start.sh, unzip for Composer,
# APCu (login rate limiting: logins fail without it)
RUN apk add --no-cache lighttpd bash unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# PHP settings, PHP-FPM pool settings, lighttpd configuration, start script
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-dfir-fm.ini"
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-dfir-fm.conf
COPY docker/lighttpd.conf /etc/lighttpd/lighttpd.conf
COPY docker/start.sh /usr/local/bin/start.sh

# Dependencies first, so that a code change doesn't reinstall them
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-interaction --no-progress

# Application code, owned by root: the web server user only writes to storage/
COPY index.php ./
COPY src src/
COPY assets assets/
COPY bin bin/
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/database storage/files storage/logs \
    && chown -R www-data:www-data storage \
    && chmod 750 storage storage/database storage/files storage/logs \
    && chmod 755 /usr/local/bin/start.sh

# Configuration: DFIRFM_<key> variables override the keys of .env (a .env file
# is optional when at least one is set). These are the container defaults;
# docker-compose.yml shows the others.
ENV DFIRFM_STORAGE_ENGINE=local \
    DFIRFM_BASE_STORAGE_DIRECTORY=/var/www/html/storage/files \
    DFIRFM_LOG_ENGINE=stderr

VOLUME ["/var/www/html/storage/database", "/var/www/html/storage/files"]

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]
