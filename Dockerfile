FROM php:8.3-cli

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install -j$(nproc) pcntl posix zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
