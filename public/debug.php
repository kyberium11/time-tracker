<?php
/**
 * Laravel Time Tracker - cPanel Debug Script
 * Upload this file to your public_html directory and access it via browser
 * Example: https://yourdomain.com/debug.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Laravel Time Tracker - Debug Information</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto; }
</style>";

// Check PHP version
echo "<div class='section'>";
echo "<h2>PHP Information</h2>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Server Software:</strong> " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<p><strong>Document Root:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p><strong>Current Directory:</strong> " . getcwd() . "</p>";
echo "</div>";

// Check required PHP extensions
echo "<div class='section'>";
echo "<h2>Required PHP Extensions</h2>";
$required_extensions = [
    'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'json', 
    'mbstring', 'openssl', 'pcre', 'pdo', 'tokenizer', 'xml'
];

foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<p class='success'>✓ $ext - Loaded</p>";
    } else {
        echo "<p class='error'>✗ $ext - Missing</p>";
    }
}
echo "</div>";

// Check file structure
echo "<div class='section'>";
echo "<h2>File Structure Check</h2>";
$required_files = [
    '../artisan',
    '../composer.json',
    '../composer.lock',
    '../.env',
    '../bootstrap/app.php',
    'index.php',
    '../storage/logs',
    '../storage/framework',
    '../vendor/autoload.php'
];

foreach ($required_files as $file) {
    if (file_exists($file)) {
        echo "<p class='success'>✓ $file - Exists</p>";
    } else {
        echo "<p class='error'>✗ $file - Missing</p>";
    }
}
echo "</div>";

// Check .env file
echo "<div class='section'>";
echo "<h2>Environment Configuration</h2>";
if (file_exists('../.env')) {
    echo "<p class='success'>✓ .env file exists</p>";
    $env_content = file_get_contents('../.env');
    
    // Check for required variables
    $required_vars = ['APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
    foreach ($required_vars as $var) {
        if (strpos($env_content, $var . '=') !== false) {
            echo "<p class='success'>✓ $var is set</p>";
        } else {
            echo "<p class='error'>✗ $var is missing</p>";
        }
    }
    
    // Check if APP_KEY is generated
    if (strpos($env_content, 'APP_KEY=base64:') !== false) {
        echo "<p class='success'>✓ APP_KEY appears to be generated</p>";
    } else {
        echo "<p class='warning'>⚠ APP_KEY may not be generated</p>";
    }
} else {
    echo "<p class='error'>✗ .env file missing</p>";
}
echo "</div>";

// Check permissions
echo "<div class='section'>";
echo "<h2>Directory Permissions</h2>";
$directories = ['../storage', '../storage/logs', '../storage/framework', '../bootstrap/cache'];
foreach ($directories as $dir) {
    if (is_dir($dir)) {
        $perms = substr(sprintf('%o', fileperms($dir)), -4);
        if (is_writable($dir)) {
            echo "<p class='success'>✓ $dir - Writable (Permissions: $perms)</p>";
        } else {
            echo "<p class='error'>✗ $dir - Not writable (Permissions: $perms)</p>";
        }
    } else {
        echo "<p class='error'>✗ $dir - Directory not found</p>";
    }
}
echo "</div>";

// Check vendor directory
echo "<div class='section'>";
echo "<h2>Composer Dependencies</h2>";
if (is_dir('../vendor')) {
    echo "<p class='success'>✓ vendor directory exists</p>";
    if (file_exists('../vendor/autoload.php')) {
        echo "<p class='success'>✓ Composer autoloader exists</p>";
    } else {
        echo "<p class='error'>✗ Composer autoloader missing</p>";
    }
} else {
    echo "<p class='error'>✗ vendor directory missing - Run composer install</p>";
}
echo "</div>";

// Test database connection
echo "<div class='section'>";
echo "<h2>Database Connection Test</h2>";
if (file_exists('../.env')) {
    $env_content = file_get_contents('../.env');
    $lines = explode("\n", $env_content);
    $db_config = [];
    
    foreach ($lines as $line) {
        if (strpos($line, 'DB_') === 0) {
            list($key, $value) = explode('=', $line, 2);
            $db_config[trim($key)] = trim($value);
        }
    }
    
    if (isset($db_config['DB_CONNECTION']) && $db_config['DB_CONNECTION'] === 'mysql') {
        try {
            $dsn = "mysql:host={$db_config['DB_HOST']};port={$db_config['DB_PORT']};dbname={$db_config['DB_DATABASE']}";
            $pdo = new PDO($dsn, $db_config['DB_USERNAME'], $db_config['DB_PASSWORD']);
            echo "<p class='success'>✓ Database connection successful</p>";
        } catch (PDOException $e) {
            echo "<p class='error'>✗ Database connection failed: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p class='warning'>⚠ Database configuration not found or not MySQL</p>";
    }
} else {
    echo "<p class='error'>✗ Cannot test database - .env file missing</p>";
}
echo "</div>";

// Check Laravel application
echo "<div class='section'>";
echo "<h2>Laravel Application Test</h2>";
if (file_exists('../bootstrap/app.php')) {
    try {
        require_once '../bootstrap/app.php';
        echo "<p class='success'>✓ Laravel bootstrap file loads successfully</p>";
    } catch (Exception $e) {
        echo "<p class='error'>✗ Laravel bootstrap failed: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p class='error'>✗ Laravel bootstrap file missing</p>";
}
echo "</div>";

// Check error logs
echo "<div class='section'>";
echo "<h2>Error Logs</h2>";
$log_files = [
    '../storage/logs/laravel.log',
    '../storage/logs/laravel-' . date('Y-m-d') . '.log'
];

foreach ($log_files as $log_file) {
    if (file_exists($log_file)) {
        echo "<h3>$log_file</h3>";
        $log_content = file_get_contents($log_file);
        if (strlen($log_content) > 0) {
            echo "<pre>" . htmlspecialchars(substr($log_content, -2000)) . "</pre>";
        } else {
            echo "<p class='info'>Log file is empty</p>";
        }
    } else {
        echo "<p class='info'>$log_file not found</p>";
    }
}
echo "</div>";

echo "<div class='section'>";
echo "<h2>Next Steps</h2>";
echo "<ol>";
echo "<li>If vendor directory is missing, you need to run 'composer install' via cPanel Terminal or File Manager</li>";
echo "<li>If .env file is missing, copy .env.example to .env and configure it</li>";
echo "<li>If APP_KEY is missing, run 'php artisan key:generate'</li>";
echo "<li>If database connection fails, check your database credentials</li>";
echo "<li>If permissions are wrong, use cPanel File Manager to set correct permissions</li>";
echo "<li>Check the error logs above for specific error messages</li>";
echo "</ol>";
echo "</div>";

echo "<p><strong>Note:</strong> Delete this debug.php file after troubleshooting for security reasons.</p>";
?>
