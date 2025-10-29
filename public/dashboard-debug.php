<?php
// Debug Tab Content
echo '<div id="debug" class="tab-content">';
echo '<h2>🔍 System Diagnostics</h2>';

// PHP Extensions Check
echo '<div class="section">';
echo '<h3>Required PHP Extensions</h3>';

$required_extensions = [
    'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'json', 
    'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml'
];

foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo '<p><span class="status-indicator status-success"></span><strong>' . $ext . ':</strong> Loaded</p>';
    } else {
        echo '<p><span class="status-indicator status-error"></span><strong>' . $ext . ':</strong> Missing</p>';
    }
}
echo '</div>';

// File Structure Check
echo '<div class="section">';
echo '<h3>File Structure Check</h3>';

$required_files = [
    '../artisan' => 'Laravel Artisan',
    '../composer.json' => 'Composer Configuration',
    '../composer.lock' => 'Composer Lock File',
    '../.env' => 'Environment Configuration',
    '../bootstrap/app.php' => 'Laravel Bootstrap',
    'index.php' => 'Public Index',
    '../storage/logs' => 'Logs Directory',
    '../storage/framework' => 'Framework Directory',
    '../vendor/autoload.php' => 'Composer Autoloader'
];

foreach ($required_files as $file => $description) {
    if (file_exists($file)) {
        echo '<p><span class="status-indicator status-success"></span><strong>' . $description . ':</strong> Exists</p>';
    } else {
        echo '<p><span class="status-indicator status-error"></span><strong>' . $description . ':</strong> Missing</p>';
    }
}
echo '</div>';

// Environment Configuration
echo '<div class="section">';
echo '<h3>Environment Configuration</h3>';

if (file_exists('../.env')) {
    echo '<p><span class="status-indicator status-success"></span><strong>.env file:</strong> Exists</p>';
    
    $env_content = file_get_contents('../.env');
    $required_vars = ['APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
    
    foreach ($required_vars as $var) {
        if (strpos($env_content, $var . '=') !== false) {
            echo '<p><span class="status-indicator status-success"></span><strong>' . $var . ':</strong> Set</p>';
        } else {
            echo '<p><span class="status-indicator status-error"></span><strong>' . $var . ':</strong> Missing</p>';
        }
    }
    
    if (strpos($env_content, 'APP_KEY=base64:') !== false) {
        echo '<p><span class="status-indicator status-success"></span><strong>APP_KEY:</strong> Generated</p>';
    } else {
        echo '<p><span class="status-indicator status-warning"></span><strong>APP_KEY:</strong> Not generated</p>';
    }
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>.env file:</strong> Missing</p>';
}
echo '</div>';

// Error Logs
echo '<div class="section">';
echo '<h3>Error Logs</h3>';

$log_files = [
    '../storage/logs/laravel.log',
    '../storage/logs/laravel-' . date('Y-m-d') . '.log'
];

$logs_found = false;
foreach ($log_files as $log_file) {
    if (file_exists($log_file)) {
        $logs_found = true;
        echo '<h4>' . basename($log_file) . '</h4>';
        $log_content = file_get_contents($log_file);
        if (strlen($log_content) > 0) {
            echo '<pre>' . htmlspecialchars(substr($log_content, -2000)) . '</pre>';
        } else {
            echo '<p class="info">Log file is empty</p>';
        }
    }
}

if (!$logs_found) {
    echo '<p class="info">No log files found</p>';
}
echo '</div>';

// Laravel Application Test
echo '<div class="section">';
echo '<h3>Laravel Application Test</h3>';

if (file_exists('../bootstrap/app.php')) {
    try {
        require_once '../bootstrap/app.php';
        echo '<p><span class="status-indicator status-success"></span><strong>Laravel Bootstrap:</strong> Loads successfully</p>';
    } catch (Exception $e) {
        echo '<p><span class="status-indicator status-error"></span><strong>Laravel Bootstrap:</strong> Failed - ' . $e->getMessage() . '</p>';
    }
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>Laravel Bootstrap:</strong> File missing</p>';
}
echo '</div>';

echo '</div>';
?>
