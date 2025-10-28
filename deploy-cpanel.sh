#!/bin/bash

# Laravel Time-Tracker cPanel Deployment Script
# Run this script on your cPanel server

echo "Starting Laravel Time-Tracker deployment..."

# Set variables
APP_DIR="/home/yourusername/public_html"
BACKUP_DIR="/home/yourusername/backups"

# Create backup directory if it doesn't exist
mkdir -p $BACKUP_DIR

# Backup current version (if exists)
if [ -d "$APP_DIR" ]; then
    echo "Creating backup of current version..."
    cp -r $APP_DIR $BACKUP_DIR/backup-$(date +%Y%m%d-%H%M%S)
fi

# Install/Update Composer dependencies
echo "Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# Install Node.js dependencies and build assets
echo "Installing Node.js dependencies..."
npm install

echo "Building production assets..."
npm run build

# Set proper permissions
echo "Setting permissions..."
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage/logs
chown -R yourusername:yourusername storage bootstrap/cache

# Generate application key if not exists
if [ ! -f .env ]; then
    echo "Creating .env file from production template..."
    cp env.production.example .env
    php artisan key:generate
fi

# Run database migrations
echo "Running database migrations..."
php artisan migrate --force

# Clear and cache configuration
echo "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Deployment completed successfully!"
echo "Don't forget to:"
echo "1. Update your .env file with production values"
echo "2. Configure your web server to point to the public directory"
echo "3. Set up SSL certificate"
echo "4. Configure ClickUp webhook URL to: https://yourdomain.com/api/integrations/clickup/webhook"
