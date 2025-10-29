<?php
/**
 * Laravel Time Tracker - cPanel Setup Script
 * Upload this file to your public_html directory and access it via browser
 * This script will help set up your Laravel application without terminal access
 * Example: https://yourdomain.com/setup.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Laravel Time Tracker - Setup Script</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto; }
    .form-group { margin: 10px 0; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-group input, .form-group select { width: 300px; padding: 5px; }
    .btn { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; }
    .btn:hover { background: #005a87; }
</style>";

// Handle form submission
if ($_POST) {
    echo "<div class='section'>";
    echo "<h2>Setup Results</h2>";
    
    // Create .env file
    if (isset($_POST['create_env'])) {
        if (file_exists('.env.example')) {
            copy('.env.example', '.env');
            echo "<p class='success'>✓ Created .env file from .env.example</p>";
        } else {
            echo "<p class='error'>✗ .env.example file not found</p>";
        }
    }
    
    // Generate APP_KEY
    if (isset($_POST['generate_key'])) {
        if (file_exists('.env')) {
            $env_content = file_get_contents('.env');
            $key = 'base64:' . base64_encode(random_bytes(32));
            $env_content = preg_replace('/APP_KEY=.*/', 'APP_KEY=' . $key, $env_content);
            file_put_contents('.env', $env_content);
            echo "<p class='success'>✓ Generated APP_KEY</p>";
        } else {
            echo "<p class='error'>✗ .env file not found. Create it first.</p>";
        }
    }
    
    // Update database configuration
    if (isset($_POST['update_db_config'])) {
        if (file_exists('.env')) {
            $env_content = file_get_contents('.env');
            
            $db_config = [
                'DB_CONNECTION' => $_POST['db_connection'] ?? 'mysql',
                'DB_HOST' => $_POST['db_host'] ?? 'localhost',
                'DB_PORT' => $_POST['db_port'] ?? '3306',
                'DB_DATABASE' => $_POST['db_database'] ?? '',
                'DB_USERNAME' => $_POST['db_username'] ?? '',
                'DB_PASSWORD' => $_POST['db_password'] ?? ''
            ];
            
            foreach ($db_config as $key => $value) {
                $env_content = preg_replace('/' . $key . '=.*/', $key . '=' . $value, $env_content);
            }
            
            file_put_contents('.env', $env_content);
            echo "<p class='success'>✓ Updated database configuration</p>";
        } else {
            echo "<p class='error'>✗ .env file not found. Create it first.</p>";
        }
    }
    
    // Set permissions
    if (isset($_POST['set_permissions'])) {
        $directories = ['storage', 'storage/logs', 'storage/framework', 'bootstrap/cache'];
        $success = true;
        
        foreach ($directories as $dir) {
            if (is_dir($dir)) {
                if (chmod($dir, 0775)) {
                    echo "<p class='success'>✓ Set permissions for $dir</p>";
                } else {
                    echo "<p class='error'>✗ Failed to set permissions for $dir</p>";
                    $success = false;
                }
            } else {
                echo "<p class='warning'>⚠ Directory $dir not found</p>";
            }
        }
        
        if ($success) {
            echo "<p class='success'>✓ Permissions set successfully</p>";
        }
    }
    
    // Create storage directories
    if (isset($_POST['create_directories'])) {
        $directories = [
            'storage/logs',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/app/public',
            'bootstrap/cache'
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (mkdir($dir, 0775, true)) {
                    echo "<p class='success'>✓ Created directory: $dir</p>";
                } else {
                    echo "<p class='error'>✗ Failed to create directory: $dir</p>";
                }
            } else {
                echo "<p class='info'>Directory already exists: $dir</p>";
            }
        }
    }
    
    echo "</div>";
}

// Show current status
echo "<div class='section'>";
echo "<h2>Current Status</h2>";

// Check .env file
if (file_exists('.env')) {
    echo "<p class='success'>✓ .env file exists</p>";
    $env_content = file_get_contents('.env');
    if (strpos($env_content, 'APP_KEY=base64:') !== false) {
        echo "<p class='success'>✓ APP_KEY is generated</p>";
    } else {
        echo "<p class='warning'>⚠ APP_KEY needs to be generated</p>";
    }
} else {
    echo "<p class='error'>✗ .env file missing</p>";
}

// Check vendor directory
if (is_dir('vendor')) {
    echo "<p class='success'>✓ vendor directory exists</p>";
} else {
    echo "<p class='error'>✗ vendor directory missing - You need to run composer install</p>";
}

// Check storage permissions
$storage_writable = is_writable('storage');
$bootstrap_writable = is_writable('bootstrap/cache');

if ($storage_writable && $bootstrap_writable) {
    echo "<p class='success'>✓ Storage directories are writable</p>";
} else {
    echo "<p class='error'>✗ Storage directories are not writable</p>";
}

echo "</div>";

// Setup forms
echo "<div class='section'>";
echo "<h2>Setup Actions</h2>";

// Create .env file
echo "<h3>1. Create .env File</h3>";
echo "<form method='post'>";
echo "<p>This will copy .env.example to .env</p>";
echo "<input type='submit' name='create_env' value='Create .env File' class='btn'>";
echo "</form>";

// Generate APP_KEY
echo "<h3>2. Generate APP_KEY</h3>";
echo "<form method='post'>";
echo "<p>This will generate a new application key</p>";
echo "<input type='submit' name='generate_key' value='Generate APP_KEY' class='btn'>";
echo "</form>";

// Update database configuration
echo "<h3>3. Update Database Configuration</h3>";
echo "<form method='post'>";
echo "<div class='form-group'>";
echo "<label>Database Connection:</label>";
echo "<select name='db_connection'>";
echo "<option value='mysql'>MySQL</option>";
echo "<option value='sqlite'>SQLite</option>";
echo "</select>";
echo "</div>";
echo "<div class='form-group'>";
echo "<label>Database Host:</label>";
echo "<input type='text' name='db_host' value='localhost'>";
echo "</div>";
echo "<div class='form-group'>";
echo "<label>Database Port:</label>";
echo "<input type='text' name='db_port' value='3306'>";
echo "</div>";
echo "<div class='form-group'>";
echo "<label>Database Name:</label>";
echo "<input type='text' name='db_database' placeholder='your_database_name'>";
echo "</div>";
echo "<div class='form-group'>";
echo "<label>Database Username:</label>";
echo "<input type='text' name='db_username' placeholder='your_database_user'>";
echo "</div>";
echo "<div class='form-group'>";
echo "<label>Database Password:</label>";
echo "<input type='password' name='db_password' placeholder='your_database_password'>";
echo "</div>";
echo "<input type='submit' name='update_db_config' value='Update Database Config' class='btn'>";
echo "</form>";

// Set permissions
echo "<h3>4. Set Directory Permissions</h3>";
echo "<form method='post'>";
echo "<p>This will set proper permissions for storage directories</p>";
echo "<input type='submit' name='set_permissions' value='Set Permissions' class='btn'>";
echo "</form>";

// Create directories
echo "<h3>5. Create Required Directories</h3>";
echo "<form method='post'>";
echo "<p>This will create missing storage and cache directories</p>";
echo "<input type='submit' name='create_directories' value='Create Directories' class='btn'>";
echo "</form>";

echo "</div>";

// Manual steps
echo "<div class='section'>";
echo "<h2>Manual Steps Required</h2>";
echo "<p>You still need to complete these steps manually:</p>";
echo "<ol>";
echo "<li><strong>Install Composer Dependencies:</strong> Use cPanel Terminal or File Manager to run: <code>composer install --no-dev --optimize-autoloader</code></li>";
echo "<li><strong>Run Database Migrations:</strong> Use cPanel Terminal to run: <code>php artisan migrate --force</code></li>";
echo "<li><strong>Cache Configuration:</strong> Use cPanel Terminal to run: <code>php artisan config:cache</code></li>";
echo "<li><strong>Cache Routes:</strong> Use cPanel Terminal to run: <code>php artisan route:cache</code></li>";
echo "<li><strong>Cache Views:</strong> Use cPanel Terminal to run: <code>php artisan view:cache</code></li>";
echo "</ol>";
echo "</div>";

// Troubleshooting
echo "<div class='section'>";
echo "<h2>Troubleshooting</h2>";
echo "<p>If you're still getting 500 errors after completing the setup:</p>";
echo "<ol>";
echo "<li>Check the error logs in cPanel Error Logs section</li>";
echo "<li>Make sure your web server is pointing to the <code>public</code> directory</li>";
echo "<li>Verify that all required PHP extensions are installed</li>";
echo "<li>Check that your database credentials are correct</li>";
echo "<li>Ensure the <code>vendor</code> directory exists and contains all dependencies</li>";
echo "</ol>";
echo "</div>";

echo "<p><strong>Security Note:</strong> Delete this setup.php file after completing the setup for security reasons.</p>";
?>
