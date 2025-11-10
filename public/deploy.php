<?php
/**
 * Laravel Time Tracker - cPanel Deployment Script
 * Upload this file to your public_html/public directory and access it via browser
 * This script will help deploy your Laravel application without terminal access
 * Example: https://yourdomain.com/deploy.php
 * 
 * SECURITY WARNING: Delete this file after deployment!
 */

// Simple password protection (change this password!)
$DEPLOY_PASSWORD = 'deploy123'; // CHANGE THIS PASSWORD!

// Check if password is set
session_start();
if (!isset($_SESSION['authenticated'])) {
    if (isset($_POST['password'])) {
        if ($_POST['password'] === $DEPLOY_PASSWORD) {
            $_SESSION['authenticated'] = true;
        } else {
            die('<h1>Access Denied</h1><p>Invalid password. <a href="?">Try again</a></p>');
        }
    } else {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Deployment Access</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 400px; margin: 100px auto; padding: 20px; }
                input { width: 100%; padding: 10px; margin: 10px 0; }
                button { width: 100%; padding: 10px; background: #007cba; color: white; border: none; cursor: pointer; }
            </style>
        </head>
        <body>
            <h1>Deployment Access</h1>
            <form method="post">
                <input type="password" name="password" placeholder="Enter deployment password" required>
                <button type="submit">Access</button>
            </form>
        </body>
        </html>
        <?php
        exit;
    }
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300); // 5 minutes for composer install

// Get base directory (parent of public)
$baseDir = dirname(__DIR__);
$publicDir = __DIR__;

// Function to execute command and return output
function executeCommand($command, $cwd = null) {
    $output = [];
    $returnVar = 0;
    
    // Set environment variables for composer
    $env = [];
    $home = getenv('HOME') ?: (getenv('HOMEPATH') ?: '/tmp');
    $composerHome = $home . '/.composer';
    
    // Create COMPOSER_HOME directory if it doesn't exist
    if (!is_dir($composerHome)) {
        @mkdir($composerHome, 0755, true);
    }
    
    // Set environment variables
    putenv('HOME=' . $home);
    putenv('COMPOSER_HOME=' . $composerHome);
    
    // Try different methods to execute commands
    if (function_exists('exec')) {
        // Set environment variables in the command
        $envCommand = 'export HOME=' . escapeshellarg($home) . ' && export COMPOSER_HOME=' . escapeshellarg($composerHome) . ' && ' . $command;
        exec($envCommand . ' 2>&1', $output, $returnVar);
    } elseif (function_exists('shell_exec')) {
        $envCommand = 'export HOME=' . escapeshellarg($home) . ' && export COMPOSER_HOME=' . escapeshellarg($composerHome) . ' && ' . $command;
        $output = shell_exec($envCommand . ' 2>&1');
        $output = $output ? explode("\n", $output) : [];
    } elseif (function_exists('system')) {
        $envCommand = 'export HOME=' . escapeshellarg($home) . ' && export COMPOSER_HOME=' . escapeshellarg($composerHome) . ' && ' . $command;
        ob_start();
        system($envCommand . ' 2>&1', $returnVar);
        $output = explode("\n", ob_get_clean());
    } else {
        return ['error' => 'No method available to execute commands. exec(), shell_exec(), and system() are disabled.'];
    }
    
    return [
        'output' => $output,
        'return_code' => $returnVar,
        'success' => $returnVar === 0
    ];
}

// Handle command execution
$result = null;
$command = null;

if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $cwd = $baseDir;
    
    switch ($action) {
        case 'composer_install':
            // Set HOME and COMPOSER_HOME environment variables
            $home = getenv('HOME') ?: (getenv('HOMEPATH') ?: '/tmp');
            $composerHome = $home . '/.composer';
            $command = 'cd ' . escapeshellarg($cwd) . ' && export HOME=' . escapeshellarg($home) . ' && export COMPOSER_HOME=' . escapeshellarg($composerHome) . ' && composer install --no-dev --optimize-autoloader --no-interaction 2>&1';
            break;
        case 'composer_install_cpanel':
            // Set HOME and COMPOSER_HOME environment variables
            $home = getenv('HOME') ?: (getenv('HOMEPATH') ?: '/tmp');
            $composerHome = $home . '/.composer';
            $command = 'cd ' . escapeshellarg($cwd) . ' && export HOME=' . escapeshellarg($home) . ' && export COMPOSER_HOME=' . escapeshellarg($composerHome) . ' && /opt/cpanel/composer/bin/composer install --no-dev --optimize-autoloader --no-interaction 2>&1';
            break;
        case 'generate_key':
            $command = 'cd ' . escapeshellarg($cwd) . ' && php artisan key:generate 2>&1';
            break;
        case 'migrate':
            $command = 'cd ' . escapeshellarg($cwd) . ' && php artisan migrate --force 2>&1';
            break;
        case 'config_cache':
            $command = 'cd ' . escapeshellarg($cwd) . ' && php artisan config:cache 2>&1';
            break;
        case 'route_cache':
            $command = 'cd ' . escapeshellarg($cwd) . ' && php artisan route:cache 2>&1';
            break;
        case 'view_cache':
            $command = 'cd ' . escapeshellarg($cwd) . ' && php artisan view:cache 2>&1';
            break;
        case 'clear_cache':
            $command = 'cd ' . escapeshellarg($cwd) . ' && php artisan cache:clear && php artisan config:clear && php artisan route:clear && php artisan view:clear 2>&1';
            break;
        case 'optimize':
            $command = 'cd ' . escapeshellarg($cwd) . ' && php artisan config:cache && php artisan route:cache && php artisan view:cache 2>&1';
            break;
        case 'create_env':
            if (file_exists($cwd . '/.env.example')) {
                copy($cwd . '/.env.example', $cwd . '/.env');
                $result = ['success' => true, 'output' => ['.env file created from .env.example']];
            } elseif (file_exists($cwd . '/env.production.example')) {
                copy($cwd . '/env.production.example', $cwd . '/.env');
                $result = ['success' => true, 'output' => ['.env file created from env.production.example']];
            } else {
                $result = ['success' => false, 'output' => ['.env.example or env.production.example not found']];
            }
            break;
        case 'create_directories':
            $dirs = [
                $cwd . '/storage/logs',
                $cwd . '/storage/framework/cache',
                $cwd . '/storage/framework/sessions',
                $cwd . '/storage/framework/views',
                $cwd . '/storage/app/public',
                $cwd . '/bootstrap/cache'
            ];
            $output = [];
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    if (mkdir($dir, 0775, true)) {
                        $output[] = "Created: $dir";
                    } else {
                        $output[] = "Failed to create: $dir";
                    }
                } else {
                    $output[] = "Already exists: $dir";
                }
            }
            $result = ['success' => true, 'output' => $output];
            break;
        case 'set_permissions':
            $dirs = [
                $cwd . '/storage',
                $cwd . '/storage/logs',
                $cwd . '/storage/framework',
                $cwd . '/bootstrap/cache'
            ];
            $output = [];
            foreach ($dirs as $dir) {
                if (is_dir($dir)) {
                    if (chmod($dir, 0775)) {
                        $output[] = "Set permissions for: $dir";
                    } else {
                        $output[] = "Failed to set permissions for: $dir";
                    }
                } else {
                    $output[] = "Directory not found: $dir";
                }
            }
            $result = ['success' => true, 'output' => $output];
            break;
        case 'assign_developer_role':
            $email = 'work.jeromealtarejos@gmail.com';
            $command = 'cd ' . escapeshellarg($cwd) . ' && php artisan user:assign-developer ' . escapeshellarg($email) . ' 2>&1';
            break;
    }
    
    if ($command && !$result) {
        $result = executeCommand($command, $cwd);
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Laravel Deployment Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .success { color: green; background: #d4edda; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .error { color: red; background: #f8d7da; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .warning { color: orange; background: #fff3cd; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .info { color: blue; background: #d1ecf1; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background: #fafafa; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto; max-height: 400px; overflow-y: auto; }
        .btn { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #005a87; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px; margin: 20px 0; }
        .status-item { padding: 10px; border: 1px solid #ddd; border-radius: 3px; }
        .command-result { margin: 20px 0; }
        .btn-group { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Laravel Time Tracker - Deployment Tool</h1>
        
        <?php if ($result): ?>
        <div class="command-result">
            <h2>Command Result</h2>
            <?php if ($result['success']): ?>
                <div class="success">
                    <strong>✓ Success!</strong>
                    <pre><?php echo htmlspecialchars(implode("\n", is_array($result['output']) ? $result['output'] : [$result['output']])); ?></pre>
                </div>
            <?php else: ?>
                <div class="error">
                    <strong>✗ Error (Return Code: <?php echo $result['return_code'] ?? 'N/A'; ?>)</strong>
                    <pre><?php echo htmlspecialchars(implode("\n", is_array($result['output']) ? $result['output'] : [$result['output']])); ?></pre>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- System Status -->
        <div class="section">
            <h2>System Status</h2>
            <div class="status-grid">
                <div class="status-item">
                    <strong>PHP Version:</strong> <?php echo phpversion(); ?>
                </div>
                <div class="status-item">
                    <strong>Base Directory:</strong> <?php echo $baseDir; ?>
                </div>
                <div class="status-item">
                    <strong>.env File:</strong> 
                    <?php echo file_exists($baseDir . '/.env') ? '<span class="success">✓ Exists</span>' : '<span class="error">✗ Missing</span>'; ?>
                </div>
                <div class="status-item">
                    <strong>vendor Directory:</strong> 
                    <?php echo is_dir($baseDir . '/vendor') ? '<span class="success">✓ Exists</span>' : '<span class="error">✗ Missing</span>'; ?>
                </div>
                <div class="status-item">
                    <strong>Storage Writable:</strong> 
                    <?php echo is_writable($baseDir . '/storage') ? '<span class="success">✓ Yes</span>' : '<span class="error">✗ No</span>'; ?>
                </div>
                <div class="status-item">
                    <strong>Composer Available:</strong> 
                    <?php 
                    $composer = shell_exec('which composer 2>&1');
                    echo $composer ? '<span class="success">✓ Yes</span>' : '<span class="warning">⚠ Check cPanel</span>'; 
                    ?>
                </div>
            </div>
        </div>
        
        <!-- Setup Steps -->
        <div class="section">
            <h2>1. Initial Setup</h2>
            <div class="btn-group">
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="create_env">
                    <button type="submit" class="btn">Create .env File</button>
                </form>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="create_directories">
                    <button type="submit" class="btn">Create Required Directories</button>
                </form>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="set_permissions">
                    <button type="submit" class="btn">Set Directory Permissions</button>
                </form>
            </div>
        </div>
        
        <!-- Composer Installation -->
        <div class="section">
            <h2>2. Install Composer Dependencies</h2>
            <p class="warning">⚠ This may take several minutes. Do not close this page.</p>
            <div class="btn-group">
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="composer_install">
                    <button type="submit" class="btn btn-success">Run Composer Install</button>
                </form>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="composer_install_cpanel">
                    <button type="submit" class="btn btn-success">Run Composer Install (cPanel)</button>
                </form>
            </div>
        </div>
        
        <!-- Laravel Commands -->
        <div class="section">
            <h2>3. Laravel Configuration</h2>
            <div class="btn-group">
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="generate_key">
                    <button type="submit" class="btn">Generate APP_KEY</button>
                </form>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="migrate">
                    <button type="submit" class="btn">Run Migrations</button>
                </form>
            </div>
        </div>
        
        <!-- Cache Commands -->
        <div class="section">
            <h2>4. Cache & Optimization</h2>
            <div class="btn-group">
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="clear_cache">
                    <button type="submit" class="btn btn-danger">Clear All Cache</button>
                </form>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="optimize">
                    <button type="submit" class="btn btn-success">Optimize (Cache All)</button>
                </form>
            </div>
        </div>
        
        <!-- Developer Role Assignment -->
        <div class="section">
            <h2>5. Developer Role Assignment</h2>
            <p class="info">Assign hidden developer role to work.jeromealtarejos@gmail.com (hidden from admin views, has admin+ access)</p>
            <div class="btn-group">
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="assign_developer_role">
                    <button type="submit" class="btn btn-success">Assign Developer Role</button>
                </form>
            </div>
        </div>
        
        <!-- Instructions -->
        <div class="section">
            <h2>📋 Deployment Checklist</h2>
            <ol>
                <li><strong>Create .env file</strong> - Click "Create .env File" button above</li>
                <li><strong>Edit .env file</strong> - Use cPanel File Manager to edit .env and add your database credentials</li>
                <li><strong>Create directories</strong> - Click "Create Required Directories"</li>
                <li><strong>Set permissions</strong> - Click "Set Directory Permissions"</li>
                <li><strong>Install dependencies</strong> - Click "Run Composer Install" (this may take 5-10 minutes)</li>
                <li><strong>Generate APP_KEY</strong> - Click "Generate APP_KEY"</li>
                <li><strong>Run migrations</strong> - Click "Run Migrations" (after database is configured)</li>
                <li><strong>Optimize</strong> - Click "Optimize (Cache All)"</li>
                <li><strong>Test</strong> - Visit your domain to test the application</li>
                <li><strong>Delete this file</strong> - For security, delete deploy.php after deployment</li>
            </ol>
        </div>
        
        <!-- Security Warning -->
        <div class="section">
            <div class="error">
                <strong>🔒 SECURITY WARNING:</strong> Delete this file (deploy.php) after completing deployment! 
                This file has access to run system commands and should not be left on your server.
            </div>
        </div>
        
        <!-- Troubleshooting -->
        <div class="section">
            <h2>🔧 Troubleshooting</h2>
            <ul>
                <li><strong>Composer not found:</strong> Try the "cPanel" version button, or contact your hosting provider</li>
                <li><strong>Permission denied:</strong> Check file permissions in cPanel File Manager</li>
                <li><strong>500 Error:</strong> Check cPanel Error Logs and ensure all steps above are completed</li>
                <li><strong>Database errors:</strong> Verify database credentials in .env file</li>
                <li><strong>Missing vendor:</strong> Composer install must complete successfully</li>
            </ul>
        </div>
    </div>
</body>
</html>

