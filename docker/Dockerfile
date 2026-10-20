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

# Application code, owned by root and read-only for the web server user
COPY index.php ./
COPY src src/
COPY assets assets/
COPY bin bin/
RUN composer dump-autoload --no-dev --optimize \
    && chmod 755 /usr/local/bin/start.sh

# Data, outside the web root, owned by www-data (uid 82):
#   /data/files     the evidence (base_storage_directory)
#   /data/database  the SQLite database (database_path)
#   /data/logs      the log file (log_file_path, with log_engine=file)
RUN mkdir -p /data/files /data/database /data/logs \
    && chown www-data:www-data /data/files /data/database /data/logs \
    && chmod 750 /data/files /data/database /data/logs

# The three paths, inside the container. DFIRFM_<key> variables override the
# keys of a mounted .env, so the same .env serves a host install and the
# container: its host paths are ignored here. A .env is optional (these
# variables are enough to start), and docker-compose.yml can override them.
ENV DFIRFM_BASE_STORAGE_DIRECTORY=/data/files \
    DFIRFM_DATABASE_PATH=/data/database/app.sqlite \
    DFIRFM_LOG_FILE_PATH=/data/logs/dfir-fm.log

VOLUME ["/data/files", "/data/database", "/data/logs"]

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]
