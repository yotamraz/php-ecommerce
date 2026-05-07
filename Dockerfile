FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo pdo_mysql zip sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY entrypoint.sh /usr/local/bin/entrypoint.sh

EXPOSE 9000
CMD ["/usr/local/bin/entrypoint.sh"]
