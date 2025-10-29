<?php
// Setup Tab Content
echo '<div id="setup" class="tab-content">';
echo '<h2>⚙️ Application Setup</h2>';

// Handle form submission
if ($_POST) {
    echo '<div class="section">';
    echo '<h3>Setup Results</h3>';
    
    $workingDir = dirname(__DIR__);
    
    // Create .env file
    if (isset($_POST['create_env'])) {
        if (file_exists('../.env.example')) {
            copy('../.env.example', '../.env');
            echo '<div class="alert alert-success">✓ Created .env file from .env.example</div>';
        } else {
            echo '<div class="alert alert-error">✗ .env.example file not found</div>';
        }
    }
    
    // Generate APP_KEY
    if (isset($_POST['generate_key'])) {
        if (file_exists('../.env')) {
            $env_content = file_get_contents('../.env');
            $key = 'base64:' . base64_encode(random_bytes(32));
            $env_content = preg_replace('/APP_KEY=.*/', 'APP_KEY=' . $key, $env_content);
            file_put_contents('../.env', $env_content);
            echo '<div class="alert alert-success">✓ Generated APP_KEY</div>';
        } else {
            echo '<div class="alert alert-error">✗ .env file not found. Create it first.</div>';
        }
    }
    
    // Update database configuration
    if (isset($_POST['update_db_config'])) {
        if (file_exists('../.env')) {
            $env_content = file_get_contents('../.env');
            
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
            
            file_put_contents('../.env', $env_content);
            echo '<div class="alert alert-success">✓ Updated database configuration</div>';
        } else {
            echo '<div class="alert alert-error">✗ .env file not found. Create it first.</div>';
        }
    }
    
    // Set permissions
    if (isset($_POST['set_permissions'])) {
        $directories = ['../storage', '../storage/logs', '../storage/framework', '../bootstrap/cache'];
        $success = true;
        
        foreach ($directories as $dir) {
            if (is_dir($dir)) {
                if (chmod($dir, 0775)) {
                    echo '<div class="alert alert-success">✓ Set permissions for ' . $dir . '</div>';
                } else {
                    echo '<div class="alert alert-error">✗ Failed to set permissions for ' . $dir . '</div>';
                    $success = false;
                }
            } else {
                echo '<div class="alert alert-warning">⚠ Directory ' . $dir . ' not found</div>';
            }
        }
        
        if ($success) {
            echo '<div class="alert alert-success">✓ Permissions set successfully</div>';
        }
    }
    
    // Create directories
    if (isset($_POST['create_directories'])) {
        $directories = [
            '../storage/logs',
            '../storage/framework/cache',
            '../storage/framework/sessions',
            '../storage/framework/views',
            '../storage/app/public',
            '../bootstrap/cache'
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (mkdir($dir, 0775, true)) {
                    echo '<div class="alert alert-success">✓ Created directory: ' . $dir . '</div>';
                } else {
                    echo '<div class="alert alert-error">✗ Failed to create directory: ' . $dir . '</div>';
                }
            } else {
                echo '<div class="alert alert-info">Directory already exists: ' . $dir . '</div>';
            }
        }
    }
    
    echo '</div>';
}

// Show current status
echo '<div class="section">';
echo '<h3>Current Status</h3>';

// Check .env file
if (file_exists('../.env')) {
    echo '<p><span class="status-indicator status-success"></span><strong>.env file:</strong> Exists</p>';
    $env_content = file_get_contents('../.env');
    if (strpos($env_content, 'APP_KEY=base64:') !== false) {
        echo '<p><span class="status-indicator status-success"></span><strong>APP_KEY:</strong> Generated</p>';
    } else {
        echo '<p><span class="status-indicator status-warning"></span><strong>APP_KEY:</strong> Needs generation</p>';
    }
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>.env file:</strong> Missing</p>';
}

// Check vendor directory
if (is_dir('../vendor')) {
    echo '<p><span class="status-indicator status-success"></span><strong>Composer Dependencies:</strong> Installed</p>';
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>Composer Dependencies:</strong> Missing</p>';
}

// Check storage permissions
$storage_writable = is_writable('../storage');
$bootstrap_writable = is_writable('../bootstrap/cache');

if ($storage_writable && $bootstrap_writable) {
    echo '<p><span class="status-indicator status-success"></span><strong>Storage Directories:</strong> Writable</p>';
} else {
    echo '<p><span class="status-indicator status-error"></span><strong>Storage Directories:</strong> Not writable</p>';
}

echo '</div>';

// Setup forms
echo '<div class="section">';
echo '<h3>Setup Actions</h3>';

// Create .env file
echo '<div class="card">';
echo '<h4>1. Create .env File</h4>';
echo '<p>This will copy .env.example to .env</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="create_env" value="Create .env File" class="btn">';
echo '</form>';
echo '</div>';

// Generate APP_KEY
echo '<div class="card">';
echo '<h4>2. Generate APP_KEY</h4>';
echo '<p>This will generate a new application key</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="generate_key" value="Generate APP_KEY" class="btn">';
echo '</form>';
echo '</div>';

// Update database configuration
echo '<div class="card">';
echo '<h4>3. Update Database Configuration</h4>';
echo '<form method="post">';
echo '<div class="form-group">';
echo '<label>Database Connection:</label>';
echo '<select name="db_connection">';
echo '<option value="mysql">MySQL</option>';
echo '<option value="sqlite">SQLite</option>';
echo '</select>';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Database Host:</label>';
echo '<input type="text" name="db_host" value="localhost">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Database Port:</label>';
echo '<input type="text" name="db_port" value="3306">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Database Name:</label>';
echo '<input type="text" name="db_database" placeholder="your_database_name">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Database Username:</label>';
echo '<input type="text" name="db_username" placeholder="your_database_user">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Database Password:</label>';
echo '<input type="password" name="db_password" placeholder="your_database_password">';
echo '</div>';
echo '<input type="submit" name="update_db_config" value="Update Database Config" class="btn">';
echo '</form>';
echo '</div>';

// Set permissions
echo '<div class="card">';
echo '<h4>4. Set Directory Permissions</h4>';
echo '<p>This will set proper permissions for storage directories</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="set_permissions" value="Set Permissions" class="btn">';
echo '</form>';
echo '</div>';

// Create directories
echo '<div class="card">';
echo '<h4>5. Create Required Directories</h4>';
echo '<p>This will create missing storage and cache directories</p>';
echo '<form method="post" style="display: inline;">';
echo '<input type="submit" name="create_directories" value="Create Directories" class="btn">';
echo '</form>';
echo '</div>';

echo '</div>';

echo '</div>';
?>
