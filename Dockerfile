# Standalone PHP API: build with backend/ as the repository/build-context root.
FROM composer:2 AS php-dependencies
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:8.3-apache-bookworm
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite headers

WORKDIR /var/www/html/backend
COPY . ./
COPY --from=php-dependencies /build/vendor ./vendor
COPY start-render.sh /usr/local/bin/start-render

RUN printf '%s\n' \
    '<VirtualHost *:__PORT__>' \
    '  ServerName localhost' \
    '  DocumentRoot /var/www/html/backend/public' \
    '  DirectoryIndex index.php' \
    '  <Directory /var/www/html/backend/public>' \
    '    Options -Indexes +FollowSymLinks' \
    '    AllowOverride All' \
    '    Require all granted' \
    '  </Directory>' \
    '  ErrorLog /proc/self/fd/2' \
    '  CustomLog /proc/self/fd/1 combined' \
    '</VirtualHost>' > /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
    'expose_php=Off' 'display_errors=Off' 'log_errors=On' \
    'error_log=/proc/self/fd/2' 'upload_max_filesize=6M' 'post_max_size=40M' \
    'max_file_uploads=30' 'memory_limit=256M' 'max_execution_time=90' \
    'date.timezone=Asia/Manila' \
    'session.save_path=/var/www/html/backend/runtime/session' \
    'session.cookie_secure=1' 'session.cookie_httponly=1' 'session.cookie_samesite=Strict' \
    > /usr/local/etc/php/conf.d/bplo-production.ini \
    && chmod +x /usr/local/bin/start-render \
    && php -r 'foreach (["pdo_mysql", "curl", "openssl", "fileinfo", "mbstring"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: " . $extension); exit(1); } }'

ENV APP_ENV=production
ENV PORT=10000
EXPOSE 10000
CMD ["start-render"]
