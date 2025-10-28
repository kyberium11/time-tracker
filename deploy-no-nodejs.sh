#!/bin/bash

# Laravel Time-Tracker cPanel Deployment Script (No Node.js)
# Run this script on your local machine before uploading to cPanel

echo "Creating deployment package for cPanel (No Node.js)..."

# Set variables
DEPLOY_DIR="deploy-package"
APP_DIR="."

# Create deployment directory
rm -rf $DEPLOY_DIR
mkdir -p $DEPLOY_DIR

echo "Copying application files..."

# Copy essential files and directories
cp -r app $DEPLOY_DIR/
cp -r bootstrap $DEPLOY_DIR/
cp -r config $DEPLOY_DIR/
cp -r database $DEPLOY_DIR/
cp -r public $DEPLOY_DIR/
cp -r resources $DEPLOY_DIR/
cp -r routes $DEPLOY_DIR/
cp -r storage $DEPLOY_DIR/
cp -r vendor $DEPLOY_DIR/

# Copy individual files
cp artisan $DEPLOY_DIR/
cp composer.json $DEPLOY_DIR/
cp composer.lock $DEPLOY_DIR/
cp package.json $DEPLOY_DIR/
cp package-lock.json $DEPLOY_DIR/
cp phpunit.xml $DEPLOY_DIR/
cp tailwind.config.js $DEPLOY_DIR/
cp tsconfig.json $DEPLOY_DIR/
cp vite.config.js $DEPLOY_DIR/
cp postcss.config.js $DEPLOY_DIR/

# Copy environment template
cp env.production.example $DEPLOY_DIR/.env.example

# Create .htaccess for main directory (if needed)
cat > $DEPLOY_DIR/.htaccess << 'EOF'
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ /public/$1 [L,QSA]
EOF

# Create deployment instructions
cat > $DEPLOY_DIR/DEPLOYMENT_INSTRUCTIONS.txt << 'EOF'
LARAVEL TIME-TRACKER - CPANEL DEPLOYMENT (NO NODE.JS)

1. UPLOAD FILES:
   - Upload all files from this package to your cPanel public_html directory
   - OR upload to a subdirectory and point your domain to it

2. DATABASE SETUP:
   - Create a MySQL database in cPanel
   - Create a database user with full privileges
   - Update .env file with your database credentials

3. ENVIRONMENT CONFIGURATION:
   - Rename .env.example to .env
   - Update the following values in .env:
     * APP_URL=https://yourdomain.com
     * DB_DATABASE=your_database_name
     * DB_USERNAME=your_database_user
     * DB_PASSWORD=your_database_password
     * CLICKUP_API_TOKEN=your_clickup_token
     * CLICKUP_SIGNING_SECRET=your_clickup_secret
     * CLICKUP_TEAM_ID=your_team_id

4. RUN LARAVEL COMMANDS (via cPanel Terminal or SSH):
   cd public_html
   php artisan key:generate
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache

5. SET PERMISSIONS:
   chmod -R 755 storage bootstrap/cache
   chmod -R 775 storage/logs

6. WEB SERVER CONFIGURATION:
   - Point your domain to the 'public' directory
   - OR use the .htaccess redirect in the main directory

7. CLICKUP WEBHOOK:
   - Update your ClickUp webhook URL to: https://yourdomain.com/api/integrations/clickup/webhook

8. TEST:
   - Visit your domain to test the application
   - Check ClickUp webhook logs in the admin panel

NOTES:
- Assets are already compiled and ready to use
- No Node.js required on the server
- Make sure PHP 8.2+ is available
- Enable required PHP extensions: BCMath, Ctype, cURL, DOM, Fileinfo, JSON, Mbstring, OpenSSL, PCRE, PDO, Tokenizer, XML
EOF

echo "Deployment package created in: $DEPLOY_DIR"
echo ""
echo "Next steps:"
echo "1. Upload the contents of '$DEPLOY_DIR' to your cPanel public_html directory"
echo "2. Follow the instructions in DEPLOYMENT_INSTRUCTIONS.txt"
echo "3. Make sure to update your .env file with production values"
echo ""
echo "Package size:"
du -sh $DEPLOY_DIR
