FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    unzip \
    postgresql-dev \
    postgresql-client \
    libzip-dev \
    freetype-dev \
    libjpeg-turbo-dev

# Install PHP extensions dengan proper configuration
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip

# Verify PostgreSQL extension is loaded
RUN php -m | grep -i pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy composer files first for better layer caching
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy existing application directory contents
COPY . /var/www

# Run composer scripts after copying all files
RUN composer run-script post-autoload-dump || true

# Create storage and cache directories if they don't exist
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && mkdir -p bootstrap/cache

# Set permissions
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

# Create a startup script with database connection test
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh \
    && echo 'cd /var/www' >> /usr/local/bin/start.sh \
    && echo 'echo "Testing PHP PostgreSQL extensions..."' >> /usr/local/bin/start.sh \
    && echo 'php -m | grep -E "(pdo_pgsql|pgsql)"' >> /usr/local/bin/start.sh \
    && echo 'echo "Running Laravel optimizations..."' >> /usr/local/bin/start.sh \
    && echo 'php artisan config:cache || true' >> /usr/local/bin/start.sh \
    && echo 'php artisan route:cache || true' >> /usr/local/bin/start.sh \
    && echo 'php artisan view:cache || true' >> /usr/local/bin/start.sh \
    && echo 'echo "Starting Laravel server..."' >> /usr/local/bin/start.sh \
    && echo 'php artisan serve --host=0.0.0.0 --port=8080' >> /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

# Switch to www-data user
USER www-data

# Expose port
EXPOSE 8080

# Use startup script
CMD ["/usr/local/bin/start.sh"]