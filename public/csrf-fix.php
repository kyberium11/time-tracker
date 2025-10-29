<?php
/**
 * CSRF Token Fix Script for Laravel Time Tracker
 * This script helps diagnose and fix 419 CSRF token errors
 * Access via: https://yourdomain.com/csrf-fix.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Laravel Time Tracker - CSRF Token Fix</h1>";
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
    .btn-success { background: #28a745; }
    .btn-success:hover { background: #218838; }
</style>";

// Handle form submission
if ($_POST) {
    echo "<div class='section'>";
    echo "<h2>CSRF Fix Results</h2>";
    
    $workingDir = dirname(__DIR__); // Laravel root directory
    
    if (isset($_POST['clear_cache'])) {
        echo "<h3>1. Clearing Laravel Caches</h3>";
        
        $commands = [
            'php artisan config:clear',
            'php artisan route:clear',
            'php artisan view:clear',
            'php artisan cache:clear',
            'php artisan session:table'
        ];
        
        foreach ($commands as $command) {
            $output = [];
            $returnCode = 0;
            
            // Change to working directory
            $oldDir = getcwd();
            chdir($workingDir);
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            // Change back
            chdir($oldDir);
            
            if ($returnCode === 0) {
                echo "<p class='success'>✓ $command - Success</p>";
            } else {
                echo "<p class='error'>✗ $command - Failed</p>";
            }
            
            if (!empty($output)) {
                echo "<pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
            }
        }
    }
    
    if (isset($_POST['fix_session_config'])) {
        echo "<h3>2. Fixing Session Configuration</h3>";
        
        // Check if .env exists
        if (file_exists($workingDir . '/.env')) {
            $envContent = file_get_contents($workingDir . '/.env');
            
            // Add/update session configuration
            $sessionConfig = [
                'SESSION_DRIVER=database',
                'SESSION_LIFETIME=120',
                'SESSION_ENCRYPT=false',
                'SESSION_PATH=/',
                'SESSION_DOMAIN=null',
                'SESSION_SECURE_COOKIE=false',
                'SESSION_HTTP_ONLY=true',
                'SESSION_SAME_SITE=lax',
                'SESSION_PARTITIONED_COOKIE=false'
            ];
            
            foreach ($sessionConfig as $config) {
                $key = explode('=', $config)[0];
                if (strpos($envContent, $key . '=') !== false) {
                    $envContent = preg_replace('/' . $key . '=.*/', $config, $envContent);
                    echo "<p class='info'>Updated $key</p>";
                } else {
                    $envContent .= "\n" . $config;
                    echo "<p class='success'>Added $key</p>";
                }
            }
            
            file_put_contents($workingDir . '/.env', $envContent);
            echo "<p class='success'>✓ Session configuration updated</p>";
        } else {
            echo "<p class='error'>✗ .env file not found</p>";
        }
    }
    
    if (isset($_POST['create_session_table'])) {
        echo "<h3>3. Creating Session Table</h3>";
        
        // Check if sessions table exists
        $output = [];
        $returnCode = 0;
        
        $oldDir = getcwd();
        chdir($workingDir);
        
        exec('php artisan migrate --force 2>&1', $output, $returnCode);
        
        chdir($oldDir);
        
        if ($returnCode === 0) {
            echo "<p class='success'>✓ Database migrations completed</p>";
        } else {
            echo "<p class='error'>✗ Database migrations failed</p>";
        }
        
        echo "<pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
    }
    
    if (isset($_POST['regenerate_key'])) {
        echo "<h3>4. Regenerating Application Key</h3>";
        
        $output = [];
        $returnCode = 0;
        
        $oldDir = getcwd();
        chdir($workingDir);
        
        exec('php artisan key:generate --force 2>&1', $output, $returnCode);
        
        chdir($oldDir);
        
        if ($returnCode === 0) {
            echo "<p class='success'>✓ Application key regenerated</p>";
        } else {
            echo "<p class='error'>✗ Application key regeneration failed</p>";
        }
        
        echo "<pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
    }
    
    if (isset($_POST['fix_sanctum_config'])) {
        echo "<h3>5. Fixing Sanctum Configuration</h3>";
        
        if (file_exists($workingDir . '/.env')) {
            $envContent = file_get_contents($workingDir . '/.env');
            
            // Add Sanctum configuration
            $sanctumConfig = [
                'SANCTUM_STATEFUL_DOMAINS=' . $_SERVER['HTTP_HOST'],
                'SESSION_DOMAIN=' . $_SERVER['HTTP_HOST']
            ];
            
            foreach ($sanctumConfig as $config) {
                $key = explode('=', $config)[0];
                if (strpos($envContent, $key . '=') !== false) {
                    $envContent = preg_replace('/' . $key . '=.*/', $config, $envContent);
                    echo "<p class='info'>Updated $key</p>";
                } else {
                    $envContent .= "\n" . $config;
                    echo "<p class='success'>Added $key</p>";
                }
            }
            
            file_put_contents($workingDir . '/.env', $envContent);
            echo "<p class='success'>✓ Sanctum configuration updated</p>";
        } else {
            echo "<p class='error'>✗ .env file not found</p>";
        }
    }
    
    echo "</div>";
}

// Show current status
echo "<div class='section'>";
echo "<h2>Current CSRF Configuration Status</h2>";

$workingDir = dirname(__DIR__);

// Check .env file
if (file_exists($workingDir . '/.env')) {
    echo "<p class='success'>✓ .env file exists</p>";
    
    $envContent = file_get_contents($workingDir . '/.env');
    
    // Check session configuration
    $sessionConfigs = [
        'SESSION_DRIVER' => 'Session driver',
        'SESSION_LIFETIME' => 'Session lifetime',
        'SESSION_ENCRYPT' => 'Session encryption',
        'SESSION_SECURE_COOKIE' => 'Secure cookies',
        'SESSION_SAME_SITE' => 'Same-site cookies'
    ];
    
    foreach ($sessionConfigs as $key => $description) {
        if (strpos($envContent, $key . '=') !== false) {
            echo "<p class='success'>✓ $description is configured</p>";
        } else {
            echo "<p class='warning'>⚠ $description is missing</p>";
        }
    }
    
    // Check APP_KEY
    if (strpos($envContent, 'APP_KEY=base64:') !== false) {
        echo "<p class='success'>✓ APP_KEY is generated</p>";
    } else {
        echo "<p class='error'>✗ APP_KEY is missing or not generated</p>";
    }
    
} else {
    echo "<p class='error'>✗ .env file missing</p>";
}

// Check if sessions table exists
if (file_exists($workingDir . '/database/migrations')) {
    $migrationFiles = glob($workingDir . '/database/migrations/*create_sessions_table.php');
    if (!empty($migrationFiles)) {
        echo "<p class='success'>✓ Sessions migration exists</p>";
    } else {
        echo "<p class='warning'>⚠ Sessions migration may be missing</p>";
    }
} else {
    echo "<p class='error'>✗ Migrations directory not found</p>";
}

// Check current domain
echo "<p><strong>Current Domain:</strong> " . $_SERVER['HTTP_HOST'] . "</p>";
echo "<p><strong>Current Protocol:</strong> " . (isset($_SERVER['HTTPS']) ? 'HTTPS' : 'HTTP') . "</p>";

echo "</div>";

// Fix forms
echo "<div class='section'>";
echo "<h2>CSRF Fix Actions</h2>";

echo "<h3>1. Clear All Caches</h3>";
echo "<form method='post'>";
echo "<p>This will clear all Laravel caches which often fixes CSRF issues.</p>";
echo "<input type='submit' name='clear_cache' value='Clear All Caches' class='btn'>";
echo "</form>";

echo "<h3>2. Fix Session Configuration</h3>";
echo "<form method='post'>";
echo "<p>This will update your .env file with proper session settings for CSRF.</p>";
echo "<input type='submit' name='fix_session_config' value='Fix Session Config' class='btn'>";
echo "</form>";

echo "<h3>3. Create Session Table</h3>";
echo "<form method='post'>";
echo "<p>This will run database migrations to ensure the sessions table exists.</p>";
echo "<input type='submit' name='create_session_table' value='Create Session Table' class='btn'>";
echo "</form>";

echo "<h3>4. Regenerate Application Key</h3>";
echo "<form method='post'>";
echo "<p>This will generate a new application key which is required for CSRF.</p>";
echo "<input type='submit' name='regenerate_key' value='Regenerate Key' class='btn'>";
echo "</form>";

echo "<h3>5. Fix Sanctum Configuration</h3>";
echo "<form method='post'>";
echo "<p>This will configure Sanctum for proper CSRF handling with your domain.</p>";
echo "<input type='submit' name='fix_sanctum_config' value='Fix Sanctum Config' class='btn'>";
echo "</form>";

echo "<h3>Run All Fixes</h3>";
echo "<form method='post'>";
echo "<p>This will run all fixes in sequence to resolve CSRF issues.</p>";
echo "<input type='submit' name='clear_cache' value='Clear Caches' class='btn'>";
echo "<input type='submit' name='fix_session_config' value='Fix Session' class='btn'>";
echo "<input type='submit' name='create_session_table' value='Create Table' class='btn'>";
echo "<input type='submit' name='regenerate_key' value='Regenerate Key' class='btn'>";
echo "<input type='submit' name='fix_sanctum_config' value='Fix Sanctum' class='btn'>";
echo "</form>";

echo "</div>";

// Instructions
echo "<div class='section'>";
echo "<h2>Common Causes of 419 CSRF Errors</h2>";
echo "<ol>";
echo "<li><strong>Missing or Invalid APP_KEY:</strong> Laravel needs a valid application key for CSRF protection</li>";
echo "<li><strong>Session Configuration Issues:</strong> Wrong session driver or configuration</li>";
echo "<li><strong>Missing Sessions Table:</strong> If using database sessions, the table must exist</li>";
echo "<li><strong>Domain Mismatch:</strong> CSRF tokens are domain-specific</li>";
echo "<li><strong>Cached Configuration:</strong> Old cached configs can cause issues</li>";
echo "<li><strong>Sanctum Configuration:</strong> Stateful domains must be properly configured</li>";
echo "</ol>";

echo "<h3>After Running Fixes</h3>";
echo "<ol>";
echo "<li>Clear your browser cache and cookies</li>";
echo "<li>Try logging in again</li>";
echo "<li>If still having issues, check the Laravel logs in storage/logs/</li>";
echo "<li>Make sure your domain is properly configured in .env</li>";
echo "</ol>";
echo "</div>";

echo "<p><strong>Security Note:</strong> Delete this csrf-fix.php file after fixing the issues for security reasons.</p>";
?>
