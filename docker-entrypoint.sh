#!/bin/bash
set -e

echo "🚀 Starting Laravel application..."

# Wait for database to be ready
echo "⏳ Waiting for PostgreSQL..."
until pg_isready -h postgres -U segal; do
  echo "PostgreSQL is unavailable - sleeping"
  sleep 2
done
echo "✅ PostgreSQL is ready!"

# Wait for Redis to be ready
echo "⏳ Waiting for Redis..."
until redis-cli -h redis ping; do
  echo "Redis is unavailable - sleeping"
  sleep 2
done
echo "✅ Redis is ready!"

# Run migrations
echo "🔄 Running migrations..."
php artisan migrate --force

# Clear and cache config
echo "🔧 Optimizing Laravel..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ Application is ready!"

# Execute the main container command
exec "$@"
