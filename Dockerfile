FROM php:8.4-fpm-bookworm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends nginx supervisor gosu libicu-dev libzip-dev libpq-dev libsqlite3-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) intl mbstring pdo_pgsql pdo_sqlite bcmath zip pcntl opcache gd \
    && pecl install redis-6.2.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
RUN printf 'upload_max_filesize=5M\npost_max_size=8M\nmemory_limit=256M\nmax_execution_time=60\n' > /usr/local/etc/php/conf.d/catalog-import.ini
RUN php -r 'exit(function_exists("imagewebp") ? 0 : 1);'
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
RUN sed -i 's/\r$//' docker/entrypoint.sh && chmod +x docker/entrypoint.sh
ENV APP_ENV=production APP_DEBUG=false
EXPOSE 8080
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
