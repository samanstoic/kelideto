FROM php:8.3-cli-alpine

# Install system dependencies & PostgreSQL / PostGIS client libraries
RUN apk add --no-cache \
    curl \
    git \
    libpq-dev \
    libzip-dev \
    postgresql-client \
    zip \
    unzip \
    nodejs \
    npm

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pgsql zip bcmath pcntl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
