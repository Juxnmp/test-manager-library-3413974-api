FROM php:8.3-cli-alpine

RUN apk add --no-cache \
    bash \
    curl \
    git \
    icu-dev \
    libpq-dev \
    libzip-dev \
    oniguruma-dev \
    unzip \
    && docker-php-ext-install \
    bcmath \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo \
    pdo_pgsql \
    pgsql \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

COPY . .

RUN mkdir -p storage/app/covers storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["sh", "-c", "php artisan migrate --force && php artisan app:provision-admin && php artisan serve --host=0.0.0.0 --port=8000"]
