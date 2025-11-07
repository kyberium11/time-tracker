# 419 CSRF Error Fix Guide

## Quick Fix Steps for Live Server

### Step 1: Update .env File

Add or update these settings in your `.env` file on the live server:

```env
APP_URL=https://yourdomain.com
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true
SESSION_DOMAIN=null
```

**Important:** Replace `yourdomain.com` with your actual domain.

### Step 2: Clear All Caches

Run these commands via cPanel Terminal or SSH:

```bash
cd public_html
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

### Step 3: Ensure Session Table Exists

If using database sessions, make sure the sessions table exists:

```bash
php artisan migrate
```

### Step 4: Verify Session Configuration

Check that your session configuration is correct:

1. **APP_URL** must match your actual domain (with https://)
2. **SESSION_SECURE_COOKIE** must be `true` for HTTPS sites
3. **SESSION_DOMAIN** should be `null` unless you have a specific subdomain setup

### Step 5: Check Browser Console

Open browser DevTools (F12) and check:
- **Network tab**: Look for the login request - check if cookies are being sent
- **Application tab → Cookies**: Verify that session cookies are being set
- **Console tab**: Look for any JavaScript errors

## Common Issues and Solutions

### Issue 1: Config Cache is Stale
**Solution:** Run `php artisan config:clear` and refresh the page

### Issue 2: Domain Mismatch
**Problem:** `APP_URL` doesn't match the actual domain
**Solution:** Update `APP_URL` in `.env` to match your actual domain exactly

### Issue 3: Session Cookie Not Being Set
**Problem:** Cookies aren't being set due to domain/secure settings
**Solution:** 
- Set `SESSION_SECURE_COOKIE=true` for HTTPS
- Set `SESSION_DOMAIN=null` (or your exact domain without protocol)
- Ensure `SESSION_SAME_SITE=lax` (or `none` if cross-site)

### Issue 4: Session Driver Not Working
**Problem:** File sessions might not work on shared hosting
**Solution:** Use database sessions:
```env
SESSION_DRIVER=database
```
Then run: `php artisan migrate` to create the sessions table

### Issue 5: CSRF Token Cookie Not Being Read
**Problem:** Browser isn't sending the XSRF-TOKEN cookie
**Solution:** 
- Clear browser cookies for your domain
- Check that cookies aren't blocked
- Verify `withCredentials: true` is set (already configured in code)

## Verification Steps

1. **Check Session Cookie:**
   - Open DevTools → Application → Cookies
   - Look for a cookie named like `your_app_session`
   - Verify it has `Secure` flag (for HTTPS)
   - Verify it has `SameSite=Lax` or `SameSite=None`

2. **Check CSRF Token Cookie:**
   - Look for `XSRF-TOKEN` cookie
   - Should have `Secure` flag for HTTPS
   - Should be sent with requests

3. **Test Login Request:**
   - Open DevTools → Network tab
   - Try to login
   - Check the login request:
     - Should have `X-XSRF-TOKEN` header
     - Should include session cookie
     - Should not return 419 error

## Still Having Issues?

1. **Check Laravel Logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Check Server Error Logs:**
   - Check cPanel Error Logs
   - Look for any PHP errors

3. **Verify File Permissions:**
   ```bash
   chmod -R 755 storage bootstrap/cache
   chmod -R 775 storage/logs
   ```

4. **Test Session Manually:**
   Create a test file `test-session.php` in `public`:
   ```php
   <?php
   require __DIR__.'/../vendor/autoload.php';
   $app = require_once __DIR__.'/../bootstrap/app.php';
   $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
   
   session_start();
   $_SESSION['test'] = 'working';
   echo "Session ID: " . session_id() . "<br>";
   echo "Session test: " . ($_SESSION['test'] ?? 'not set') . "<br>";
   echo "Cookie domain: " . ini_get('session.cookie_domain') . "<br>";
   echo "Cookie secure: " . (ini_get('session.cookie_secure') ? 'true' : 'false') . "<br>";
   ```
   Visit `https://yourdomain.com/test-session.php` and check the output.

## Code Changes Made

1. **Updated `config/session.php`:**
   - Auto-detects HTTPS from `APP_URL`
   - Sets secure cookies automatically for HTTPS

2. **Updated `resources/js/bootstrap.ts`:**
   - Configured axios to send CSRF tokens
   - Enabled `withCredentials` for cookie handling

3. **Updated `env.production.example`:**
   - Added proper session configuration

## After Fixing

Once the issue is resolved:
1. Delete any test files (`test-session.php`, `csrf-fix.php`, etc.)
2. Clear browser cache and cookies
3. Test login again

