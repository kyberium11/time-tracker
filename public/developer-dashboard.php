<?php
/**
 * Laravel Time Tracker - Developer Dashboard
 * Comprehensive development and debugging tool for cPanel deployment
 * Access via: https://yourdomain.com/developer-dashboard.php
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white; 
            border-radius: 10px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header { 
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%); 
            color: white; 
            padding: 20px; 
            text-align: center;
        }
        .header h1 { margin: 0; font-size: 2.5em; }
        .header p { margin: 10px 0 0 0; opacity: 0.8; }
        .nav { 
            background: #34495e; 
            padding: 0; 
            margin: 0; 
            display: flex; 
            flex-wrap: wrap;
        }
        .nav-item { 
            background: none; 
            border: none; 
            color: white; 
            padding: 15px 20px; 
            cursor: pointer; 
            transition: all 0.3s;
            flex: 1;
            min-width: 150px;
        }
        .nav-item:hover, .nav-item.active { 
            background: #2c3e50; 
            transform: translateY(-2px);
        }
        .content { 
            padding: 30px; 
            min-height: 500px;
        }
        .tab-content { 
            display: none; 
        }
        .tab-content.active { 
            display: block; 
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
        .section h3 { 
            margin-top: 0; 
            color: #2c3e50; 
            border-bottom: 2px solid #3498db; 
            padding-bottom: 10px;
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
            background: linear-gradient(135deg, #3498db, #2980b9); 
            color: white; 
            padding: 12px 24px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
            margin: 5px; 
            font-size: 14px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { 
            background: linear-gradient(135deg, #2980b9, #1f4e79); 
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .btn-success { background: linear-gradient(135deg, #27ae60, #229954); }
        .btn-success:hover { background: linear-gradient(135deg, #229954, #1e8449); }
        .btn-danger { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .btn-danger:hover { background: linear-gradient(135deg, #c0392b, #a93226); }
        .btn-warning { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .btn-warning:hover { background: linear-gradient(135deg, #e67e22, #d35400); }
        .form-group { 
            margin: 15px 0; 
        }
        .form-group label { 
            display: block; 
            margin-bottom: 5px; 
            font-weight: bold; 
            color: #2c3e50;
        }
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; 
            max-width: 400px; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            font-size: 14px;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
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
        .alert { 
            padding: 15px; 
            margin: 15px 0; 
            border-radius: 5px; 
            border-left: 4px solid;
        }
        .alert-success { 
            background: #d5f4e6; 
            border-color: #27ae60; 
            color: #155724; 
        }
        .alert-error { 
            background: #f8d7da; 
            border-color: #e74c3c; 
            color: #721c24; 
        }
        .alert-warning { 
            background: #fff3cd; 
            border-color: #f39c12; 
            color: #856404; 
        }
        .alert-info { 
            background: #d1ecf1; 
            border-color: #3498db; 
            color: #0c5460; 
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🚀 Developer Dashboard</h1>
            <p>Laravel Time Tracker - Complete Development & Debugging Suite</p>
        </div>
        
        <div class='nav'>
            <button class='nav-item active' onclick='showTab(\"overview\")'>📊 Overview</button>
            <button class='nav-item' onclick='showTab(\"debug\")'>🔍 Debug</button>
            <button class='nav-item' onclick='showTab(\"setup\")'>⚙️ Setup</button>
            <button class='nav-item' onclick='showTab(\"laravel\")'>🔧 Laravel</button>
            <button class='nav-item' onclick='showTab(\"csrf\")'>🛡️ CSRF Fix</button>
            <button class='nav-item' onclick='showTab(\"users\")'>👥 Users</button>
        </div>
        
        <div class='content'>";

// Include all functionality
if (file_exists('dashboard-overview.php')) {
    include 'dashboard-overview.php';
} else {
    echo '<div id="overview" class="tab-content active"><h2>Overview module not found</h2></div>';
}

if (file_exists('dashboard-debug.php')) {
    include 'dashboard-debug.php';
} else {
    echo '<div id="debug" class="tab-content"><h2>Debug module not found</h2></div>';
}

if (file_exists('dashboard-setup.php')) {
    include 'dashboard-setup.php';
} else {
    echo '<div id="setup" class="tab-content"><h2>Setup module not found</h2></div>';
}

if (file_exists('dashboard-laravel.php')) {
    include 'dashboard-laravel.php';
} else {
    echo '<div id="laravel" class="tab-content"><h2>Laravel module not found</h2></div>';
}

if (file_exists('dashboard-csrf.php')) {
    include 'dashboard-csrf.php';
} else {
    echo '<div id="csrf" class="tab-content"><h2>CSRF module not found</h2></div>';
}

if (file_exists('dashboard-users.php')) {
    include 'dashboard-users.php';
} else {
    echo '<div id="users" class="tab-content"><h2>Users module not found</h2></div>';
}

echo "        </div>
    </div>
    
    <script>
        function showTab(tabName) {
            // Hide all tab contents
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all nav items
            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach(item => item.classList.remove('active'));
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked nav item
            event.target.classList.add('active');
        }
        
        // Auto-refresh status every 30 seconds
        setInterval(function() {
            if (document.getElementById('overview').classList.contains('active')) {
                location.reload();
            }
        }, 30000);
    </script>
</body>
</html>";
?>
