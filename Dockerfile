# =============================================================================
# Dockerfile para Laravel API en Google Cloud Run
# Multi-stage build optimizado para producción
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: Composer dependencies
# -----------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .

RUN composer dump-autoload --optimize --no-dev

# -----------------------------------------------------------------------------
# Stage 2: Production image
# -----------------------------------------------------------------------------
FROM php:8.3-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        pcntl \
        bcmath \
        gd \
        zip \
        mbstring \
        opcache \
    && rm -rf /var/cache/apk/*

# Configure PHP for production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Opcache configuration for better performance
COPY <<EOF /usr/local/etc/php/conf.d/opcache.ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.fast_shutdown=1
EOF

# PHP configuration tweaks
COPY <<EOF /usr/local/etc/php/conf.d/custom.ini
memory_limit=512M
max_execution_time=300
upload_max_filesize=64M
post_max_size=64M
expose_php=Off
EOF

WORKDIR /var/www

# Copy application
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

# Create necessary directories and set permissions
RUN mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Cloud Run uses PORT environment variable
ENV PORT=8080

# Expose port
EXPOSE 8080

# Start script
COPY <<'EOF' /usr/local/bin/start.sh
#!/bin/sh
set -e

echo "Starting Laravel application..."

# Run migrations if AUTO_MIGRATE is set
if [ "$AUTO_MIGRATE" = "true" ]; then
    echo "Running migrations..."
    php artisan migrate --force
fi

# Cache configuration
echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start PHP built-in server (suitable for Cloud Run)
echo "Starting server on port $PORT..."
exec php artisan serve --host=0.0.0.0 --port=$PORT
EOF

RUN chmod +x /usr/local/bin/start.sh

USER www-data

CMD ["/usr/local/bin/start.sh"]
