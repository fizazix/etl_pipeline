FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libzip-dev \
    default-mysql-client \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN APP_KEY=base64:RW1haWxMaW5rVmVyaWZ5S2V5MTIzNDU2Nzg5MA== \
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts

COPY . .

RUN APP_KEY=base64:RW1haWxMaW5rVmVyaWZ5S2V5MTIzNDU2Nzg5MA== \
    composer dump-autoload --optimize

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN cp .env.example .env

EXPOSE 8000
