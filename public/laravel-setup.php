<?php
/**
 * Laravel Time Tracker - Laravel Setup Script
 * Upload this file to your public directory and access it via browser
 * This script runs Laravel commands without terminal access
 * Example: https://yourdomain.com/laravel-setup.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Laravel Time Tracker - Laravel Setup Commands</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .info { color: blue; }
    .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto; }
    .btn { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; margin: 5px; }
    .btn:hover { background: #005a87; }
    .btn-danger { background: #dc3545; }
    .btn-danger:hover { background: #c82333; }
    .btn-success { background: #28a745; }
    .btn-success:hover { background: #218838; }
</style>";

// Function to execute commands
function executeCommand($command, $workingDir = null) {
    $output = [];
    $returnCode = 0;
    
    if ($workingDir === null) {
        $workingDir = dirname(__DIR__); // Go to parent directory (Laravel root)
    }
    
    // Change to the working directory
    $oldDir = getcwd();
    chdir($workingDir);
    
    // Set environment variables for Composer
    $env = [
        'HOME' => $workingDir,
        'COMPOSER_HOME' => $workingDir . '/.composer',
        'COMPOSER_CACHE_DIR' => $workingDir . '/.composer/cache'
    ];
    
    // Create composer home directory if it doesn't exist
    if (!is_dir($env['COMPOSER_HOME'])) {
        mkdir($env['COMPOSER_HOME'], 0755, true);
    }
    
    // Build the command with environment variables
    $envString = '';
    foreach ($env as $key => $value) {
        $envString .= "$key=" . escapeshellarg($value) . ' ';
    }
    
    $fullCommand = $envString . $command . ' 2>&1';
    
    // Execute the command
    exec($fullCommand, $output, $returnCode);
    
    // Change back to original directory
    chdir($oldDir);
    
    return [
        'output' => $output,
        'return_code' => $returnCode,
        'command' => $fullCommand,
        'working_dir' => $workingDir
    ];
}

// Function to check if command exists
function commandExists($command) {
    $result = executeCommand("which $command");
    return $result['return_code'] === 0;
}

// Function specifically for Composer commands
function executeComposerCommand($command, $workingDir = null) {
    if ($workingDir === null) {
        $workingDir = dirname(__DIR__); // Go to parent directory (Laravel root)
    }
    
    // Try different composer paths
    $composerPaths = [
        'composer',
        '/opt/cpanel/composer/bin/composer',
        '/usr/local/bin/composer',
        '/usr/bin/composer',
        'php /opt/cpanel/composer/bin/composer.phar',
        'php /usr/local/bin/composer.phar'
    ];
    
    $composerFound = false;
    $composerPath = null;
    
    foreach ($composerPaths as $path) {
        if (file_exists($path) || commandExists($path)) {
            $composerPath = $path;
            $composerFound = true;
            break;
        }
    }
    
    if (!$composerFound) {
        return [
            'output' => ['Composer not found. Please install Composer or contact your hosting provider.'],
            'return_code' => 1,
            'command' => 'composer not found',
            'working_dir' => $workingDir
        ];
    }
    
    // Set up Composer environment
    $env = [
        'HOME' => $workingDir,
        'COMPOSER_HOME' => $workingDir . '/.composer',
        'COMPOSER_CACHE_DIR' => $workingDir . '/.composer/cache',
        'COMPOSER_ALLOW_SUPERUSER' => '1'
    ];
    
    // Create composer directories
    if (!is_dir($env['COMPOSER_HOME'])) {
        mkdir($env['COMPOSER_HOME'], 0755, true);
    }
    if (!is_dir($env['COMPOSER_CACHE_DIR'])) {
        mkdir($env['COMPOSER_CACHE_DIR'], 0755, true);
    }
    
    // Build environment string
    $envString = '';
    foreach ($env as $key => $value) {
        $envString .= "$key=" . escapeshellarg($value) . ' ';
    }
    
    $fullCommand = $envString . $composerPath . ' ' . $command . ' 2>&1';
    
    $output = [];
    $returnCode = 0;
    
    // Change to working directory
    $oldDir = getcwd();
    chdir($workingDir);
    
    // Execute command
    exec($fullCommand, $output, $returnCode);
    
    // Change back
    chdir($oldDir);
    
    return [
        'output' => $output,
        'return_code' => $returnCode,
        'command' => $fullCommand,
        'working_dir' => $workingDir
    ];
}

// Handle form submission
if ($_POST) {
    echo "<div class='section'>";
    echo "<h2>Command Execution Results</h2>";
    
    $workingDir = dirname(__DIR__); // Laravel root directory
    echo "<p><strong>Working Directory:</strong> $workingDir</p>";
    
    if (isset($_POST['run_composer'])) {
        echo "<h3>1. Installing Composer Dependencies</h3>";
        
        $result = executeComposerCommand('install --no-dev --optimize-autoloader --no-interaction', $workingDir);
        
        if ($result['return_code'] === 0) {
            echo "<p class='success'>✓ Composer dependencies installed successfully</p>";
        } else {
            echo "<p class='error'>✗ Composer installation failed</p>";
        }
        
        echo "<pre>" . htmlspecialchars(implode("\n", $result['output'])) . "</pre>";
    }
    
    if (isset($_POST['run_migrate'])) {
        echo "<h3>2. Running Database Migrations</h3>";
        
        if (!file_exists($workingDir . '/.env')) {
            echo "<p class='error'>✗ .env file not found. Please create it first using the setup script.</p>";
        } else {
            $result = executeCommand('php artisan migrate --force', $workingDir);
            
            if ($result['return_code'] === 0) {
                echo "<p class='success'>✓ Database migrations completed successfully</p>";
            } else {
                echo "<p class='error'>✗ Database migrations failed</p>";
            }
            
            echo "<pre>" . htmlspecialchars(implode("\n", $result['output'])) . "</pre>";
        }
    }
    
    if (isset($_POST['run_config_cache'])) {
        echo "<h3>3. Caching Configuration</h3>";
        
        $result = executeCommand('php artisan config:cache', $workingDir);
        
        if ($result['return_code'] === 0) {
            echo "<p class='success'>✓ Configuration cached successfully</p>";
        } else {
            echo "<p class='error'>✗ Configuration caching failed</p>";
        }
        
        echo "<pre>" . htmlspecialchars(implode("\n", $result['output'])) . "</pre>";
    }
    
    if (isset($_POST['run_route_cache'])) {
        echo "<h3>4. Caching Routes</h3>";
        
        $result = executeCommand('php artisan route:cache', $workingDir);
        
        if ($result['return_code'] === 0) {
            echo "<p class='success'>✓ Routes cached successfully</p>";
        } else {
            echo "<p class='error'>✗ Route caching failed</p>";
        }
        
        echo "<pre>" . htmlspecialchars(implode("\n", $result['output'])) . "</pre>";
    }
    
    if (isset($_POST['run_view_cache'])) {
        echo "<h3>5. Caching Views</h3>";
        
        $result = executeCommand('php artisan view:cache', $workingDir);
        
        if ($result['return_code'] === 0) {
            echo "<p class='success'>✓ Views cached successfully</p>";
        } else {
            echo "<p class='error'>✗ View caching failed</p>";
        }
        
        echo "<pre>" . htmlspecialchars(implode("\n", $result['output'])) . "</pre>";
    }
    
    if (isset($_POST['run_all'])) {
        echo "<h3>Running All Commands</h3>";
        
        $commands = [
            'composer' => 'composer install --no-dev --optimize-autoloader --no-interaction',
            'migrate' => 'php artisan migrate --force',
            'config' => 'php artisan config:cache',
            'route' => 'php artisan route:cache',
            'view' => 'php artisan view:cache'
        ];
        
        foreach ($commands as $name => $command) {
            echo "<h4>Running: $name</h4>";
            
            if ($name === 'composer') {
                $result = executeComposerCommand('install --no-dev --optimize-autoloader --no-interaction', $workingDir);
            } else {
                $result = executeCommand($command, $workingDir);
            }
            
            if ($result['return_code'] === 0) {
                echo "<p class='success'>✓ $name completed successfully</p>";
            } else {
                echo "<p class='error'>✗ $name failed</p>";
            }
            
            echo "<pre>" . htmlspecialchars(implode("\n", $result['output'])) . "</pre>";
        }
    }
    
    echo "</div>";
}

// Show current status
echo "<div class='section'>";
echo "<h2>Current Status</h2>";

$workingDir = dirname(__DIR__);
echo "<p><strong>Laravel Root Directory:</strong> $workingDir</p>";

// Check if .env exists
if (file_exists($workingDir . '/.env')) {
    echo "<p class='success'>✓ .env file exists</p>";
} else {
    echo "<p class='error'>✗ .env file missing - Run setup script first</p>";
}

// Check if vendor directory exists
if (is_dir($workingDir . '/vendor')) {
    echo "<p class='success'>✓ vendor directory exists</p>";
} else {
    echo "<p class='error'>✗ vendor directory missing - Run composer install</p>";
}

// Check if artisan exists
if (file_exists($workingDir . '/artisan')) {
    echo "<p class='success'>✓ artisan file exists</p>";
} else {
    echo "<p class='error'>✗ artisan file missing</p>";
}

// Check PHP version
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";

// Check if we can execute commands
if (function_exists('exec')) {
    echo "<p class='success'>✓ exec() function is available</p>";
} else {
    echo "<p class='error'>✗ exec() function is disabled - Contact your hosting provider</p>";
}

echo "</div>";

// Command forms
echo "<div class='section'>";
echo "<h2>Laravel Setup Commands</h2>";

echo "<h3>Individual Commands</h3>";

// Composer install
echo "<form method='post' style='display: inline-block; margin: 5px;'>";
echo "<input type='submit' name='run_composer' value='Install Composer Dependencies' class='btn'>";
echo "</form>";

// Database migrations
echo "<form method='post' style='display: inline-block; margin: 5px;'>";
echo "<input type='submit' name='run_migrate' value='Run Database Migrations' class='btn'>";
echo "</form>";

// Config cache
echo "<form method='post' style='display: inline-block; margin: 5px;'>";
echo "<input type='submit' name='run_config_cache' value='Cache Configuration' class='btn'>";
echo "</form>";

// Route cache
echo "<form method='post' style='display: inline-block; margin: 5px;'>";
echo "<input type='submit' name='run_route_cache' value='Cache Routes' class='btn'>";
echo "</form>";

// View cache
echo "<form method='post' style='display: inline-block; margin: 5px;'>";
echo "<input type='submit' name='run_view_cache' value='Cache Views' class='btn'>";
echo "</form>";

echo "<br><br>";

// Run all commands
echo "<h3>Run All Commands</h3>";
echo "<form method='post'>";
echo "<p>This will run all commands in sequence. Make sure you have completed the basic setup first.</p>";
echo "<input type='submit' name='run_all' value='Run All Commands' class='btn btn-success'>";
echo "</form>";

echo "</div>";

// Instructions
echo "<div class='section'>";
echo "<h2>Instructions</h2>";
echo "<ol>";
echo "<li><strong>First:</strong> Make sure you have run the setup script to create .env file and set permissions</li>";
echo "<li><strong>Composer Install:</strong> Installs all PHP dependencies (this may take a few minutes)</li>";
echo "<li><strong>Database Migrations:</strong> Creates all database tables (requires .env to be configured)</li>";
echo "<li><strong>Cache Commands:</strong> Optimizes the application for production</li>";
echo "<li><strong>Run All:</strong> Executes all commands in the correct order</li>";
echo "</ol>";

echo "<h3>Prerequisites</h3>";
echo "<ul>";
echo "<li>.env file must exist and be configured</li>";
echo "<li>Database credentials must be correct</li>";
echo "<li>exec() function must be enabled on your server</li>";
echo "<li>Composer must be available (or contact hosting provider)</li>";
echo "</ul>";
echo "</div>";

echo "<p><strong>Security Note:</strong> Delete this laravel-setup.php file after completing the setup for security reasons.</p>";
?>
