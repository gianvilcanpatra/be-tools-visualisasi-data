#!/bin/sh

# Set default port if not provided, handle both cases
if [ -z "$PORT" ]; then
    if [ -n "$port" ]; then
        export PORT=$port
    else
        export PORT=80
    fi
fi

echo "Using port: $PORT"

# Replace LISTEN_PORT with actual port
sed -i "s/LISTEN_PORT/$PORT/g" /etc/nginx/nginx.conf

# Debug: Show the replaced line
echo "Nginx configuration after port replacement:"
grep -n "listen.*default_server" /etc/nginx/nginx.conf

# Navigate to app directory
cd /app

# Generate application key if not exists
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate app key if it doesn't exist
php artisan key:generate --no-interaction --force

# Clear and cache configuration for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run database migrations (optional - uncomment if needed)
# php artisan migrate --force

# Set proper permissions
chown -R www-data:www-data /app/storage /app/bootstrap/cache
chmod -R 775 /app/storage /app/bootstrap/cache

# Start PHP-FPM in daemon mode
php-fpm -D

# Wait for PHP-FPM to start
sleep 2

# Check if PHP-FPM is running
if ! pgrep -f "php-fpm: master process" > /dev/null; then
    echo "Error: PHP-FPM failed to start"
    exit 1
fi

echo "Starting Nginx..."
# Start Nginx
nginx