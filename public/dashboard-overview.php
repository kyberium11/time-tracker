<?php
// Overview Tab Content
echo '<div id="overview" class="tab-content active">';
echo '<h2>📊 System Overview</h2>';

$workingDir = dirname(__DIR__);

// System Status
echo '<div class="section">';
echo '<h3>System Status</h3>';

// PHP Version
echo '<div class="card">';
echo '<h4>PHP Information</h4>';
echo '<p><span class="status-indicator status-info"></span><strong>PHP Version:</strong> ' . phpversion() . '</p>';
echo '<p><span class="status-indicator status-info"></span><strong>Server:</strong> ' . $_SERVER['SERVER_SOFTWARE'] . '</p>';
echo '<p><span class="status-indicator status-info"></span><strong>Document Root:</strong> ' . $_SERVER['DOCUMENT_ROOT'] . '</p>';
echo '<p><span class="status-indicator status-info"></span><strong>Current Domain:</strong> ' . $_SERVER['HTTP_HOST'] . '</p>';
echo '</div>';

// Laravel Status
echo '<div class="card">';
echo '<h4>Laravel Application</h4>';

// Check .env file
if (file_exists($workingDir . '/.env')) {
    echo '<p><span class="status-indicator status-success"></span><strong>.env file:</strong> Exists</p>';
    
    $envContent = file_get_contents($workingDir . '/.env');
    if (strpos($envContent, 'APP_KEY=base64:') !== false) {
        echo '<p><span class="status-indicator status-success"></span><strong>APP_KEY:</strong> Generated</p>';
    } else {
        echo '<p><span class="status-indicator status-error"></span><strong>APP_KEY:</strong> Missing</p>';
    }
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>.env file:</strong> Missing</p>';
}

// Check vendor directory
if (is_dir($workingDir . '/vendor')) {
    echo '<p><span class="status-indicator status-success"></span><strong>Composer Dependencies:</strong> Installed</p>';
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>Composer Dependencies:</strong> Missing</p>';
}

// Check artisan
if (file_exists($workingDir . '/artisan')) {
    echo '<p><span class="status-indicator status-success"></span><strong>Artisan:</strong> Available</p>';
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>Artisan:</strong> Missing</p>';
}

echo '</div>';

// Database Status
echo '<div class="card">';
echo '<h4>Database Status</h4>';

if (file_exists($workingDir . '/.env')) {
    $envContent = file_get_contents($workingDir . '/.env');
    $lines = explode("\n", $envContent);
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
            echo '<p><span class="status-indicator status-success"></span><strong>Database Connection:</strong> Success</p>';
        } catch (PDOException $e) {
            echo '<p><span class="status-indicator status-error"></span><strong>Database Connection:</strong> Failed - ' . $e->getMessage() . '</p>';
        }
    } else {
        echo '<p><span class="status-indicator status-warning"></span><strong>Database:</strong> Not configured</p>';
    }
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>Database:</strong> .env file missing</p>';
}

echo '</div>';

// Storage Status
echo '<div class="card">';
echo '<h4>Storage & Permissions</h4>';

$directories = ['../storage', '../storage/logs', '../storage/framework', '../bootstrap/cache'];
foreach ($directories as $dir) {
    if (is_dir($dir)) {
        $perms = substr(sprintf('%o', fileperms($dir)), -4);
        if (is_writable($dir)) {
            echo '<p><span class="status-indicator status-success"></span><strong>' . $dir . ':</strong> Writable (' . $perms . ')</p>';
        } else {
            echo '<p><span class="status-indicator status-error"></span><strong>' . $dir . ':</strong> Not writable (' . $perms . ')</p>';
        }
    } else {
        echo '<p><span class="status-indicator status-error"></span><strong>' . $dir . ':</strong> Missing</p>';
    }
}

echo '</div>';

echo '</div>';

// Quick Actions
echo '<div class="section">';
echo '<h3>Quick Actions</h3>';
echo '<div class="grid">';

echo '<div class="card">';
echo '<h4>🔧 Laravel Commands</h4>';
echo '<p>Run essential Laravel commands for setup and maintenance.</p>';
echo '<a href="?tab=laravel" class="btn">Go to Laravel Tools</a>';
echo '</div>';

echo '<div class="card">';
echo '<h4>🛡️ CSRF Fix</h4>';
echo '<p>Fix 419 CSRF token errors and session issues.</p>';
echo '<a href="?tab=csrf" class="btn">Fix CSRF Issues</a>';
echo '</div>';

echo '<div class="card">';
echo '<h4>👥 User Management</h4>';
echo '<p>Create and manage user accounts for the application.</p>';
echo '<a href="?tab=users" class="btn">Manage Users</a>';
echo '</div>';

echo '<div class="card">';
echo '<h4>🔍 Debug Tools</h4>';
echo '<p>Comprehensive debugging and diagnostic tools.</p>';
echo '<a href="?tab=debug" class="btn">Run Diagnostics</a>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '</div>';
?>
