<?php
/**
 * Simple Developer Dashboard - Debug Version
 * This is a simplified version to help identify 500 errors
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Laravel Time Tracker - Developer Dashboard</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background: #f5f5f5;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white; 
            border-radius: 10px; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header { 
            background: #2c3e50; 
            color: white; 
            padding: 20px; 
            text-align: center;
        }
        .content { 
            padding: 30px; 
        }
        .success { color: #27ae60; font-weight: bold; }
        .error { color: #e74c3c; font-weight: bold; }
        .warning { color: #f39c12; font-weight: bold; }
        .info { color: #3498db; font-weight: bold; }
        .section { 
            margin: 20px 0; 
            padding: 20px; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            background: #f8f9fa;
        }
        pre { 
            background: #2c3e50; 
            color: #ecf0f1; 
            padding: 15px; 
            border-radius: 5px; 
            overflow-x: auto; 
            font-size: 14px;
        }
        .btn { 
            background: #3498db; 
            color: white; 
            padding: 12px 24px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
            margin: 5px; 
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { 
            background: #2980b9; 
        }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #229954; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        .btn-warning { background: #f39c12; }
        .btn-warning:hover { background: #e67e22; }
        .form-group { 
            margin: 15px 0; 
        }
        .form-group label { 
            display: block; 
            margin-bottom: 5px; 
            font-weight: bold; 
        }
        .form-group input, .form-group select { 
            width: 100%; 
            max-width: 400px; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
        }
        .grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
            gap: 20px; 
            margin: 20px 0;
        }
        .card { 
            background: white; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            padding: 20px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status-indicator { 
            display: inline-block; 
            width: 12px; 
            height: 12px; 
            border-radius: 50%; 
            margin-right: 8px;
        }
        .status-success { background: #27ae60; }
        .status-error { background: #e74c3c; }
        .status-warning { background: #f39c12; }
        .status-info { background: #3498db; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🚀 Developer Dashboard - Simple Version</h1>
            <p>Laravel Time Tracker - Development & Debugging Tools</p>
        </div>
        
        <div class='content'>";

// Basic system check
echo '<div class="section">';
echo '<h2>🔍 System Status</h2>';

// PHP Version
echo '<p><span class="status-indicator status-info"></span><strong>PHP Version:</strong> ' . phpversion() . '</p>';
echo '<p><span class="status-indicator status-info"></span><strong>Server:</strong> ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . '</p>';
echo '<p><span class="status-indicator status-info"></span><strong>Document Root:</strong> ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . '</p>';
echo '<p><span class="status-indicator status-info"></span><strong>Current Domain:</strong> ' . ($_SERVER['HTTP_HOST'] ?? 'Unknown') . '</p>';

// Check if we can access parent directory
$workingDir = dirname(__DIR__);
echo '<p><span class="status-indicator status-info"></span><strong>Laravel Root:</strong> ' . $workingDir . '</p>';

// Check .env file
if (file_exists($workingDir . '/.env')) {
    echo '<p><span class="status-indicator status-success"></span><strong>.env file:</strong> Exists</p>';
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

// Quick actions
echo '<div class="section">';
echo '<h2>🛠️ Quick Actions</h2>';

echo '<div class="grid">';

echo '<div class="card">';
echo '<h3>🔧 Laravel Commands</h3>';
echo '<p>Run essential Laravel commands for setup and maintenance.</p>';
echo '<a href="laravel-setup.php" class="btn">Go to Laravel Tools</a>';
echo '</div>';

echo '<div class="card">';
echo '<h3>🛡️ CSRF Fix</h3>';
echo '<p>Fix 419 CSRF token errors and session issues.</p>';
echo '<a href="csrf-fix.php" class="btn">Fix CSRF Issues</a>';
echo '</div>';

echo '<div class="card">';
echo '<h3>⚙️ Setup Tools</h3>';
echo '<p>Configure environment and set up the application.</p>';
echo '<a href="setup.php" class="btn">Go to Setup</a>';
echo '</div>';

echo '<div class="card">';
echo '<h3>🔍 Debug Tools</h3>';
echo '<p>Comprehensive debugging and diagnostic tools.</p>';
echo '<a href="debug.php" class="btn">Run Diagnostics</a>';
echo '</div>';

echo '</div>';
echo '</div>';

// User creation form
echo '<div class="section">';
echo '<h2>👥 Create User</h2>';

if ($_POST && isset($_POST['create_user'])) {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    
    if (empty($name) || empty($email) || empty($password)) {
        echo '<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 15px 0;">✗ All fields are required</div>';
    } else {
        try {
            // Include Laravel bootstrap
            require_once $workingDir . '/vendor/autoload.php';
            $app = require_once $workingDir . '/bootstrap/app.php';
            
            // Create user
            $user = new \App\Models\User();
            $user->name = $name;
            $user->email = $email;
            $user->password = \Illuminate\Support\Facades\Hash::make($password);
            $user->role = $role;
            $user->email_verified_at = now();
            $user->save();
            
            echo '<div style="background: #d5f4e6; color: #155724; padding: 15px; border-radius: 5px; margin: 15px 0;">✓ User created successfully! ID: ' . $user->id . '</div>';
            
        } catch (Exception $e) {
            echo '<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 15px 0;">✗ User creation failed: ' . $e->getMessage() . '</div>';
        }
    }
}

echo '<form method="post">';
echo '<div class="form-group">';
echo '<label>Full Name *</label>';
echo '<input type="text" name="name" required placeholder="John Doe">';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Email Address *</label>';
echo '<input type="email" name="email" required placeholder="john@example.com">';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Password *</label>';
echo '<input type="password" name="password" required placeholder="Enter a secure password">';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Role</label>';
echo '<select name="role">';
echo '<option value="user">User</option>';
echo '<option value="manager">Manager</option>';
echo '<option value="admin">Admin</option>';
echo '</select>';
echo '</div>';

echo '<input type="submit" name="create_user" value="Create User" class="btn btn-success">';
echo '</form>';

echo '</div>';

// Error information
echo '<div class="section">';
echo '<h2>🐛 Debug Information</h2>';

echo '<h3>PHP Error Information</h3>';
echo '<pre>';
echo 'Error Reporting: ' . error_reporting() . "\n";
echo 'Display Errors: ' . ini_get('display_errors') . "\n";
echo 'Log Errors: ' . ini_get('log_errors') . "\n";
echo 'Error Log: ' . ini_get('error_log') . "\n";
echo '</pre>';

echo '<h3>File Permissions</h3>';
$directories = ['../storage', '../storage/logs', '../storage/framework', '../bootstrap/cache'];
foreach ($directories as $dir) {
    if (is_dir($dir)) {
        $perms = substr(sprintf('%o', fileperms($dir)), -4);
        $writable = is_writable($dir) ? 'Writable' : 'Not Writable';
        echo '<p><strong>' . $dir . ':</strong> ' . $perms . ' (' . $writable . ')</p>';
    } else {
        echo '<p><strong>' . $dir . ':</strong> Directory not found</p>';
    }
}

echo '</div>';

echo "        </div>
    </div>
</body>
</html>";
?>
