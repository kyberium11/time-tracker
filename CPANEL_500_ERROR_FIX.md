# cPanel 500 Error Fix Guide

## Quick Fix Steps (No Terminal Required)

### Step 1: Upload Debug Script
1. Upload `debug.php` to your `public_html` directory
2. Visit `https://yourdomain.com/debug.php` in your browser
3. This will show you exactly what's wrong

### Step 2: Upload Setup Script
1. Upload `setup.php` to your `public_html` directory
2. Visit `https://yourdomain.com/setup.php` in your browser
3. Follow the setup wizard to configure your application

### Step 3: Manual Steps (via cPanel File Manager)

#### 3.1 Install Composer Dependencies
1. Go to cPanel → File Manager
2. Navigate to your `public_html` directory
3. Look for `composer.json` file
4. If you have cPanel Terminal access:
   - Run: `composer install --no-dev --optimize-autoloader`
5. If no Terminal access:
   - Contact your hosting provider to install dependencies
   - Or use a local development environment to run `composer install` and upload the `vendor` folder

#### 3.2 Create .env File
1. In File Manager, navigate to `public_html`
2. Copy `.env.example` and rename it to `.env`
3. Edit the `.env` file with your database credentials:
   ```
   APP_NAME="Time Tracker"
   APP_ENV=production
   APP_KEY=base64:your_generated_key_here
   APP_DEBUG=false
   APP_URL=https://yourdomain.com
   
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=your_database_name
   DB_USERNAME=your_database_user
   DB_PASSWORD=your_database_password
   ```

#### 3.3 Generate Application Key
1. Use the setup.php script to generate the key
2. Or manually edit .env and set: `APP_KEY=base64:your_generated_key_here`

#### 3.4 Set Directory Permissions
1. In File Manager, right-click on `storage` folder
2. Select "Permissions" or "Change Permissions"
3. Set to `775` (or `755` if 775 doesn't work)
4. Do the same for `bootstrap/cache` folder

#### 3.5 Create Required Directories
Create these directories if they don't exist:
- `storage/logs`
- `storage/framework/cache`
- `storage/framework/sessions`
- `storage/framework/views`
- `storage/app/public`
- `bootstrap/cache`

### Step 4: Run Laravel Commands (if Terminal available)
If you have cPanel Terminal access, run these commands:
```bash
cd public_html
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Step 5: Configure Web Server
Make sure your web server is pointing to the `public` directory:
1. In cPanel, go to "Subdomains" or "Addon Domains"
2. Set the document root to `public_html/public` (not just `public_html`)

## Common 500 Error Causes

### 1. Missing vendor Directory
**Problem:** Composer dependencies not installed
**Solution:** Run `composer install` or upload vendor folder

### 2. Missing .env File
**Problem:** Laravel can't find environment configuration
**Solution:** Copy `.env.example` to `.env` and configure it

### 3. Missing APP_KEY
**Problem:** Laravel needs an application key
**Solution:** Run `php artisan key:generate` or set manually in .env

### 4. Wrong File Permissions
**Problem:** Laravel can't write to storage directories
**Solution:** Set storage and bootstrap/cache to 775 permissions

### 5. Database Connection Issues
**Problem:** Wrong database credentials
**Solution:** Check and update database settings in .env

### 6. Missing PHP Extensions
**Problem:** Required PHP extensions not installed
**Solution:** Contact hosting provider to install missing extensions

### 7. Wrong Document Root
**Problem:** Web server pointing to wrong directory
**Solution:** Point to `public_html/public` directory

## File Structure After Fix

Your `public_html` should look like this:
```
public_html/
├── app/
├── bootstrap/
├── config/
├── database/
├── public/          # This is your web root
│   ├── index.php
│   ├── .htaccess
│   └── build/
├── resources/
├── routes/
├── storage/
├── vendor/          # Must exist with all dependencies
├── .env             # Must exist and be configured
├── .htaccess
└── artisan
```

## Testing Your Fix

1. Visit your domain - should show the Laravel application
2. Check for any remaining errors in cPanel Error Logs
3. Test user registration and login
4. Verify time tracking functionality

## Security Notes

- Delete `debug.php` and `setup.php` after fixing the issues
- Make sure `.env` file is not accessible via web browser
- Set proper file permissions (755 for directories, 644 for files)

## Still Having Issues?

1. Check cPanel Error Logs for specific error messages
2. Contact your hosting provider for PHP extension issues
3. Verify your database credentials are correct
4. Make sure your hosting supports Laravel requirements
