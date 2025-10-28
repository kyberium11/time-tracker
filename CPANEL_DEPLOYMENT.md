# cPanel Deployment Guide

This guide explains how to deploy the Laravel Time Tracker using cPanel's Git Version Control feature.

## Prerequisites

- cPanel hosting with Git Version Control enabled
- PHP 8.2+ support
- MySQL database
- Composer support
- Required PHP extensions: BCMath, Ctype, cURL, DOM, Fileinfo, JSON, Mbstring, OpenSSL, PCRE, PDO, Tokenizer, XML

## Setup Instructions

### 1. Enable Git Version Control in cPanel

1. Login to your cPanel
2. Find "Git Version Control" in the Software section
3. Click "Create" to create a new repository
4. Set the following:
   - **Repository Name**: `time-tracker`
   - **Clone URL**: `https://github.com/kyberium11/time-tracker.git`
   - **Branch**: `main`
   - **Deploy Directory**: `/home/yourusername/public_html` (replace with your actual username)

### 2. Configure Repository

1. In Git Version Control, click "Manage" next to your repository
2. Click "Pull or Deploy"
3. The `.cpanel.yml` file will automatically handle the deployment

### 3. Database Setup

1. Create a MySQL database in cPanel
2. Create a database user with full privileges
3. Note down the credentials

### 4. Environment Configuration

1. In cPanel File Manager, navigate to `public_html`
2. Rename `.env.example` to `.env`
3. Update the following values in `.env`:

```env
APP_NAME="Time Tracker"
APP_ENV=production
APP_KEY=base64:your_generated_key
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

# ClickUp Integration
CLICKUP_API_TOKEN=your_clickup_api_token
CLICKUP_SIGNING_SECRET=your_clickup_signing_secret
CLICKUP_TEAM_ID=your_clickup_team_id
CLICKUP_ALLOW_UNVERIFIED=false
```

### 5. Run Laravel Commands

Via cPanel Terminal or SSH:

```bash
cd public_html
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 6. Set Permissions

```bash
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage/logs
```

## Automatic Deployment

The `.cpanel.yml` file is configured to:

- ✅ Copy all application files to the deployment directory
- ✅ Set proper file permissions
- ✅ Run Laravel optimization commands
- ✅ Create automatic backups before deployment
- ✅ Handle environment configuration

## Manual Deployment

If you need to deploy manually:

1. Go to Git Version Control in cPanel
2. Click "Manage" next to your repository
3. Click "Pull or Deploy"
4. Select the branch (usually `main`)
5. Click "Deploy"

## Post-Deployment

### 1. Test the Application

- Visit your domain to ensure the application loads
- Test user registration and login
- Verify time tracking functionality

### 2. Configure ClickUp Webhook

1. Go to ClickUp Settings → Integrations → Webhooks
2. Update the webhook URL to: `https://yourdomain.com/api/integrations/clickup/webhook`
3. Ensure events include: `taskCreated`, `taskUpdated`, `taskDeleted`, `taskAssigneeUpdated`

### 3. Check Webhook Logs

- Login as admin
- Navigate to ClickUp Logs to verify webhook functionality

## Troubleshooting

### Common Issues:

1. **500 Internal Server Error**:
   - Check file permissions
   - Verify `.env` file exists and is configured
   - Check error logs in cPanel

2. **Database Connection Error**:
   - Verify database credentials in `.env`
   - Ensure database user has proper permissions

3. **Assets Not Loading**:
   - Assets are pre-built and included in the repository
   - Check if `public/build` directory exists

4. **ClickUp Webhook Not Working**:
   - Verify the webhook URL is accessible
   - Check if your hosting allows POST requests
   - Review webhook logs in the admin panel

### File Structure After Deployment:

```
public_html/
├── app/
├── bootstrap/
├── config/
├── database/
├── public/          # Web root
│   ├── index.php
│   ├── .htaccess
│   └── build/       # Compiled assets
├── resources/
├── routes/
├── storage/
├── vendor/
├── .env
├── .htaccess        # Main directory redirect
└── artisan
```

## Updates

To update your application:

1. Push changes to the GitHub repository
2. Go to cPanel Git Version Control
3. Click "Pull or Deploy" to deploy the latest changes

The `.cpanel.yml` file will automatically handle the deployment process.
