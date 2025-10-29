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
    
    // Execute the command
    exec($command . ' 2>&1', $output, $returnCode);
    
    // Change back to original directory
    chdir($oldDir);
    
    return [
        'output' => $output,
        'return_code' => $returnCode,
        'command' => $command,
        'working_dir' => $workingDir
    ];
}

// Function to check if command exists
function commandExists($command) {
    $result = executeCommand("which $command");
    return $result['return_code'] === 0;
}

// Handle form submission
if ($_POST) {
    echo "<div class='section'>";
    echo "<h2>Command Execution Results</h2>";
    
    $workingDir = dirname(__DIR__); // Laravel root directory
    echo "<p><strong>Working Directory:</strong> $workingDir</p>";
    
    if (isset($_POST['run_composer'])) {
        echo "<h3>1. Installing Composer Dependencies</h3>";
        
        // Check if composer exists
        if (commandExists('composer')) {
            $result = executeCommand('composer install --no-dev --optimize-autoloader --no-interaction', $workingDir);
        } else {
            // Try alternative composer paths
            $composerPaths = [
                '/opt/cpanel/composer/bin/composer',
                '/usr/local/bin/composer',
                '/usr/bin/composer',
                'composer'
            ];
            
            $composerFound = false;
            foreach ($composerPaths as $composerPath) {
                if (file_exists($composerPath) || commandExists($composerPath)) {
                    $result = executeCommand("$composerPath install --no-dev --optimize-autoloader --no-interaction", $workingDir);
                    $composerFound = true;
                    break;
                }
            }
            
            if (!$composerFound) {
                echo "<p class='error'>✗ Composer not found. Please install Composer or contact your hosting provider.</p>";
                $result = ['output' => ['Composer not found'], 'return_code' => 1];
            }
        }
        
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
                // Try different composer paths
                $composerPaths = [
                    'composer',
                    '/opt/cpanel/composer/bin/composer',
                    '/usr/local/bin/composer',
                    '/usr/bin/composer'
                ];
                
                $composerFound = false;
                foreach ($composerPaths as $composerPath) {
                    if (file_exists($composerPath) || commandExists($composerPath)) {
                        $result = executeCommand("$composerPath install --no-dev --optimize-autoloader --no-interaction", $workingDir);
                        $composerFound = true;
                        break;
                    }
                }
                
                if (!$composerFound) {
                    echo "<p class='error'>✗ Composer not found</p>";
                    continue;
                }
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
