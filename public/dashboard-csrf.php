<?php
// CSRF Tab Content
echo '<div id="csrf" class="tab-content">';
echo '<h2>🛡️ CSRF Token Fix</h2>';

// Handle form submission
if ($_POST) {
    echo '<div class="section">';
    echo '<h3>CSRF Fix Results</h3>';
    
    $workingDir = dirname(__DIR__);
    
    if (isset($_POST['clear_cache'])) {
        echo '<h4>1. Clearing Laravel Caches</h4>';
        
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
            
            $oldDir = getcwd();
            chdir($workingDir);
            exec($command . ' 2>&1', $output, $returnCode);
            chdir($oldDir);
            
            if ($returnCode === 0) {
                echo '<div class="alert alert-success">✓ ' . $command . ' - Success</div>';
            } else {
                echo '<div class="alert alert-error">✗ ' . $command . ' - Failed</div>';
            }
            
            if (!empty($output)) {
                echo '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
            }
        }
    }
    
    if (isset($_POST['fix_session_config'])) {
        echo '<h4>2. Fixing Session Configuration</h4>';
        
        if (file_exists($workingDir . '/.env')) {
            $envContent = file_get_contents($workingDir . '/.env');
            
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
                    echo '<div class="alert alert-info">Updated ' . $key . '</div>';
                } else {
                    $envContent .= "\n" . $config;
                    echo '<div class="alert alert-success">Added ' . $key . '</div>';
                }
            }
            
            file_put_contents($workingDir . '/.env', $envContent);
            echo '<div class="alert alert-success">✓ Session configuration updated</div>';
        } else {
            echo '<div class="alert alert-error">✗ .env file not found</div>';
        }
    }
    
    if (isset($_POST['create_session_table'])) {
        echo '<h4>3. Creating Session Table</h4>';
        
        $output = [];
        $returnCode = 0;
        
        $oldDir = getcwd();
        chdir($workingDir);
        exec('php artisan migrate --force 2>&1', $output, $returnCode);
        chdir($oldDir);
        
        if ($returnCode === 0) {
            echo '<div class="alert alert-success">✓ Database migrations completed</div>';
        } else {
            echo '<div class="alert alert-error">✗ Database migrations failed</div>';
        }
        
        echo '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
    }
    
    if (isset($_POST['regenerate_key'])) {
        echo '<h4>4. Regenerating Application Key</h4>';
        
        $output = [];
        $returnCode = 0;
        
        $oldDir = getcwd();
        chdir($workingDir);
        exec('php artisan key:generate --force 2>&1', $output, $returnCode);
        chdir($oldDir);
        
        if ($returnCode === 0) {
            echo '<div class="alert alert-success">✓ Application key regenerated</div>';
        } else {
            echo '<div class="alert alert-error">✗ Application key regeneration failed</div>';
        }
        
        echo '<pre>' . htmlspecialchars(implode("\n", $output)) . '</pre>';
    }
    
    if (isset($_POST['fix_sanctum_config'])) {
        echo '<h4>5. Fixing Sanctum Configuration</h4>';
        
        if (file_exists($workingDir . '/.env')) {
            $envContent = file_get_contents($workingDir . '/.env');
            
            $sanctumConfig = [
                'SANCTUM_STATEFUL_DOMAINS=' . $_SERVER['HTTP_HOST'],
                'SESSION_DOMAIN=' . $_SERVER['HTTP_HOST']
            ];
            
            foreach ($sanctumConfig as $config) {
                $key = explode('=', $config)[0];
                if (strpos($envContent, $key . '=') !== false) {
                    $envContent = preg_replace('/' . $key . '=.*/', $config, $envContent);
                    echo '<div class="alert alert-info">Updated ' . $key . '</div>';
                } else {
                    $envContent .= "\n" . $config;
                    echo '<div class="alert alert-success">Added ' . $key . '</div>';
                }
            }
            
            file_put_contents($workingDir . '/.env', $envContent);
            echo '<div class="alert alert-success">✓ Sanctum configuration updated</div>';
        } else {
            echo '<div class="alert alert-error">✗ .env file not found</div>';
        }
    }
    
    echo '</div>';
}

// Show current status
echo '<div class="section">';
echo '<h3>Current CSRF Configuration Status</h3>';

$workingDir = dirname(__DIR__);

if (file_exists($workingDir . '/.env')) {
    echo '<p><span class="status-indicator status-success"></span><strong>.env file:</strong> Exists</p>';
    
    $envContent = file_get_contents($workingDir . '/.env');
    
    $sessionConfigs = [
        'SESSION_DRIVER' => 'Session driver',
        'SESSION_LIFETIME' => 'Session lifetime',
        'SESSION_ENCRYPT' => 'Session encryption',
        'SESSION_SECURE_COOKIE' => 'Secure cookies',
        'SESSION_SAME_SITE' => 'Same-site cookies'
    ];
    
    foreach ($sessionConfigs as $key => $description) {
        if (strpos($envContent, $key . '=') !== false) {
            echo '<p><span class="status-indicator status-success"></span><strong>' . $description . ':</strong> Configured</p>';
        } else {
            echo '<p><span class="status-indicator status-warning"></span><strong>' . $description . ':</strong> Missing</p>';
        }
    }
    
    if (strpos($envContent, 'APP_KEY=base64:') !== false) {
        echo '<p><span class="status-indicator status-success"></span><strong>APP_KEY:</strong> Generated</p>';
    } else {
        echo '<p><span class="status-indicator status-error"></span><strong>APP_KEY:</strong> Missing or not generated</p>';
    }
    
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>.env file:</strong> Missing</p>';
}

if (file_exists($workingDir . '/database/migrations')) {
    $migrationFiles = glob($workingDir . '/database/migrations/*create_sessions_table.php');
    if (!empty($migrationFiles)) {
        echo '<p><span class="status-indicator status-success"></span><strong>Sessions migration:</strong> Exists</p>';
    } else {
        echo '<p><span class="status-indicator status-warning"></span><strong>Sessions migration:</strong> May be missing</p>';
    }
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>Migrations directory:</strong> Not found</p>';
}

echo '<p><strong>Current Domain:</strong> ' . $_SERVER['HTTP_HOST'] . '</p>';
echo '<p><strong>Current Protocol:</strong> ' . (isset($_SERVER['HTTPS']) ? 'HTTPS' : 'HTTP') . '</p>';

echo '</div>';

// Fix forms
echo '<div class="section">';
echo '<h3>CSRF Fix Actions</h3>';

echo '<div class="grid">';

echo '<div class="card">';
echo '<h4>Clear All Caches</h4>';
echo '<p>This will clear all Laravel caches which often fixes CSRF issues.</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="clear_cache" value="Clear All Caches" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Fix Session Configuration</h4>';
echo '<p>This will update your .env file with proper session settings for CSRF.</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="fix_session_config" value="Fix Session Config" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Create Session Table</h4>';
echo '<p>This will run database migrations to ensure the sessions table exists.</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="create_session_table" value="Create Session Table" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Regenerate Application Key</h4>';
echo '<p>This will generate a new application key which is required for CSRF.</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="regenerate_key" value="Regenerate Key" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Fix Sanctum Configuration</h4>';
echo '<p>This will configure Sanctum for proper CSRF handling with your domain.</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="fix_sanctum_config" value="Fix Sanctum Config" class="btn">';
echo '</form>';
echo '</div>';

echo '<div class="card">';
echo '<h4>Run All Fixes</h4>';
echo '<p>This will run all fixes in sequence to resolve CSRF issues.</p>';
echo '<form method="post">';
echo '<input type="submit" name="clear_cache" value="Clear Caches" class="btn">';
echo '<input type="submit" name="fix_session_config" value="Fix Session" class="btn">';
echo '<input type="submit" name="create_session_table" value="Create Table" class="btn">';
echo '<input type="submit" name="regenerate_key" value="Regenerate Key" class="btn">';
echo '<input type="submit" name="fix_sanctum_config" value="Fix Sanctum" class="btn">';
echo '</form>';
echo '</div>';

echo '</div>';

echo '</div>';

// Instructions
echo '<div class="section">';
echo '<h3>Common Causes of 419 CSRF Errors</h3>';
echo '<ol>';
echo '<li><strong>Missing or Invalid APP_KEY:</strong> Laravel needs a valid application key for CSRF protection</li>';
echo '<li><strong>Session Configuration Issues:</strong> Wrong session driver or configuration</li>';
echo '<li><strong>Missing Sessions Table:</strong> If using database sessions, the table must exist</li>';
echo '<li><strong>Domain Mismatch:</strong> CSRF tokens are domain-specific</li>';
echo '<li><strong>Cached Configuration:</strong> Old cached configs can cause issues</li>';
echo '<li><strong>Sanctum Configuration:</strong> Stateful domains must be properly configured</li>';
echo '</ol>';

echo '<h4>After Running Fixes</h4>';
echo '<ol>';
echo '<li>Clear your browser cache and cookies</li>';
echo '<li>Try logging in again</li>';
echo '<li>If still having issues, check the Laravel logs in storage/logs/</li>';
echo '<li>Make sure your domain is properly configured in .env</li>';
echo '</ol>';
echo '</div>';

echo '</div>';
?>
