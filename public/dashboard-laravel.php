<?php
// Laravel Tab Content
echo '<div id="laravel" class="tab-content">';
echo '<h2>🔧 Laravel Commands</h2>';

// Function to execute commands
function executeCommand($command, $workingDir = null) {
    $output = [];
    $returnCode = 0;
    
    if ($workingDir === null) {
        $workingDir = dirname(__DIR__);
    }
    
    $oldDir = getcwd();
    chdir($workingDir);
    
    $env = [
        'HOME' => $workingDir,
        'COMPOSER_HOME' => $workingDir . '/.composer',
        'COMPOSER_CACHE_DIR' => $workingDir . '/.composer/cache'
    ];
    
    if (!is_dir($env['COMPOSER_HOME'])) {
        mkdir($env['COMPOSER_HOME'], 0755, true);
    }
    
    $envString = '';
    foreach ($env as $key => $value) {
        $envString .= "$key=" . escapeshellarg($value) . ' ';
    }
    
    $fullCommand = $envString . $command . ' 2>&1';
    exec($fullCommand, $output, $returnCode);
    
    chdir($oldDir);
    
    return [
        'output' => $output,
        'return_code' => $returnCode,
        'command' => $fullCommand,
        'working_dir' => $workingDir
    ];
}

// Function specifically for Composer commands
function executeComposerCommand($command, $workingDir = null) {
    if ($workingDir === null) {
        $workingDir = dirname(__DIR__);
    }
    
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
        if (file_exists($path) || command_exists($path)) {
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
    
    $env = [
        'HOME' => $workingDir,
        'COMPOSER_HOME' => $workingDir . '/.composer',
        'COMPOSER_CACHE_DIR' => $workingDir . '/.composer/cache',
        'COMPOSER_ALLOW_SUPERUSER' => '1'
    ];
    
    if (!is_dir($env['COMPOSER_HOME'])) {
        mkdir($env['COMPOSER_HOME'], 0755, true);
    }
    if (!is_dir($env['COMPOSER_CACHE_DIR'])) {
        mkdir($env['COMPOSER_CACHE_DIR'], 0755, true);
    }
    
    $envString = '';
    foreach ($env as $key => $value) {
        $envString .= "$key=" . escapeshellarg($value) . ' ';
    }
    
    $fullCommand = $envString . $composerPath . ' ' . $command . ' 2>&1';
    
    $output = [];
    $returnCode = 0;
    
    $oldDir = getcwd();
    chdir($workingDir);
    exec($fullCommand, $output, $returnCode);
    chdir($oldDir);
    
    return [
        'output' => $output,
        'return_code' => $returnCode,
        'command' => $fullCommand,
        'working_dir' => $workingDir
    ];
}

function command_exists($command) {
    $result = executeCommand("which $command");
    return $result['return_code'] === 0;
}

// Handle form submission
if ($_POST) {
    echo '<div class="section">';
    echo '<h3>Command Execution Results</h3>';
    
    $workingDir = dirname(__DIR__);
    echo '<p><strong>Working Directory:</strong> ' . $workingDir . '</p>';
    
    if (isset($_POST['run_composer'])) {
        echo '<h4>1. Installing Composer Dependencies</h4>';
        
        $result = executeComposerCommand('install --no-dev --optimize-autoloader --no-interaction', $workingDir);
        
        if ($result['return_code'] === 0) {
            echo '<div class="alert alert-success">✓ Composer dependencies installed successfully</div>';
        } else {
            echo '<div class="alert alert-error">✗ Composer installation failed</div>';
        }
        
        echo '<pre>' . htmlspecialchars(implode("\n", $result['output'])) . '</pre>';
    }
    
    if (isset($_POST['run_migrate'])) {
        echo '<h4>2. Running Database Migrations</h4>';
        
        if (!file_exists($workingDir . '/.env')) {
            echo '<div class="alert alert-error">✗ .env file not found. Please create it first using the setup script.</div>';
        } else {
            $result = executeCommand('php artisan migrate --force', $workingDir);
            
            if ($result['return_code'] === 0) {
                echo '<div class="alert alert-success">✓ Database migrations completed successfully</div>';
            } else {
                echo '<div class="alert alert-error">✗ Database migrations failed</div>';
            }
            
            echo '<pre>' . htmlspecialchars(implode("\n", $result['output'])) . '</pre>';
        }
    }
    
    if (isset($_POST['run_config_cache'])) {
        echo '<h4>3. Caching Configuration</h4>';
        
        $result = executeCommand('php artisan config:cache', $workingDir);
        
        if ($result['return_code'] === 0) {
            echo '<div class="alert alert-success">✓ Configuration cached successfully</div>';
        } else {
            echo '<div class="alert alert-error">✗ Configuration caching failed</div>';
        }
        
        echo '<pre>' . htmlspecialchars(implode("\n", $result['output'])) . '</pre>';
    }
    
    if (isset($_POST['run_route_cache'])) {
        echo '<h4>4. Caching Routes</h4>';
        
        $result = executeCommand('php artisan route:cache', $workingDir);
        
        if ($result['return_code'] === 0) {
            echo '<div class="alert alert-success">✓ Routes cached successfully</div>';
        } else {
            echo '<div class="alert alert-error">✗ Route caching failed</div>';
        }
        
        echo '<pre>' . htmlspecialchars(implode("\n", $result['output'])) . '</pre>';
    }
    
    if (isset($_POST['run_view_cache'])) {
        echo '<h4>5. Caching Views</h4>';
        
        $result = executeCommand('php artisan view:cache', $workingDir);
        
        if ($result['return_code'] === 0) {
            echo '<div class="alert alert-success">✓ Views cached successfully</div>';
        } else {
            echo '<div class="alert alert-error">✗ View caching failed</div>';
        }
        
        echo '<pre>' . htmlspecialchars(implode("\n", $result['output'])) . '</pre>';
    }
    
    if (isset($_POST['run_all'])) {
        echo '<h4>Running All Commands</h4>';
        
        $commands = [
            'composer' => 'composer install --no-dev --optimize-autoloader --no-interaction',
            'migrate' => 'php artisan migrate --force',
            'config' => 'php artisan config:cache',
            'route' => 'php artisan route:cache',
            'view' => 'php artisan view:cache'
        ];
        
        foreach ($commands as $name => $command) {
            echo '<h5>Running: ' . $name . '</h5>';
            
            if ($name === 'composer') {
                $result = executeComposerCommand('install --no-dev --optimize-autoloader --no-interaction', $workingDir);
            } else {
                $result = executeCommand($command, $workingDir);
            }
            
            if ($result['return_code'] === 0) {
                echo '<div class="alert alert-success">✓ ' . $name . ' completed successfully</div>';
            } else {
                echo '<div class="alert alert-error">✗ ' . $name . ' failed</div>';
            }
            
            echo '<pre>' . htmlspecialchars(implode("\n", $result['output'])) . '</pre>';
        }
    }
    
    echo '</div>';
}

// Show current status
echo '<div class="section">';
echo '<h3>Current Status</h3>';

$workingDir = dirname(__DIR__);
echo '<p><strong>Laravel Root Directory:</strong> ' . $workingDir . '</p>';

if (file_exists($workingDir . '/.env')) {
    echo '<p><span class="status-indicator status-success"></span><strong>.env file:</strong> Exists</p>';
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>.env file:</strong> Missing - Run setup first</p>';
}

if (is_dir($workingDir . '/vendor')) {
    echo '<p><span class="status-indicator status-success"></span><strong>vendor directory:</strong> Exists</p>';
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>vendor directory:</strong> Missing - Run composer install</p>';
}

if (file_exists($workingDir . '/artisan')) {
    echo '<p><span class="status-indicator status-success"></span><strong>artisan file:</strong> Exists</p>';
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>artisan file:</strong> Missing</p>';
}

echo '<p><strong>PHP Version:</strong> ' . phpversion() . '</p>';

if (function_exists('exec')) {
    echo '<p><span class="status-indicator status-success"></span><strong>exec() function:</strong> Available</p>';
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>exec() function:</strong> Disabled - Contact hosting provider</p>';
}

echo '</div>';

// Command forms
echo '<div class="section">';
echo '<h3>Laravel Setup Commands</h3>';

echo '<div class="grid">';

echo '<div class="card">';
echo '<h4>Install Composer Dependencies</h4>';
echo '<p>Installs all PHP dependencies required by Laravel</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="run_composer" value="Install Dependencies" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Run Database Migrations</h4>';
echo '<p>Creates all database tables and structure</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="run_migrate" value="Run Migrations" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Cache Configuration</h4>';
echo '<p>Optimizes configuration loading for production</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="run_config_cache" value="Cache Config" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Cache Routes</h4>';
echo '<p>Optimizes route loading for production</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="run_route_cache" value="Cache Routes" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Cache Views</h4>';
echo '<p>Compiles and caches Blade templates</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="run_view_cache" value="Cache Views" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Run All Commands</h4>';
echo '<p>Executes all commands in the correct sequence</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="run_all" value="Run All Commands" class="btn btn-success">';
echo '</form>';
echo '</div>';

echo '</div>';

echo '</div>';

echo '</div>';
?>
