<?php
// Users Tab Content
echo '<div id="users" class="tab-content">';
echo '<h2>👥 User Management</h2>';

// Handle form submission
if ($_POST) {
    echo '<div class="section">';
    echo '<h3>User Creation Results</h3>';
    
    $workingDir = dirname(__DIR__);
    
    if (isset($_POST['create_user'])) {
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $team_id = $_POST['team_id'] ?? null;
        $clickup_user_id = $_POST['clickup_user_id'] ?? null;
        
        if (empty($name) || empty($email) || empty($password)) {
            echo '<div class="alert alert-error">✗ All fields are required</div>';
        } else {
            // Create user using Laravel's User model
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
                $user->team_id = $team_id ?: null;
                $user->clickup_user_id = $clickup_user_id ?: null;
                $user->email_verified_at = now();
                $user->save();
                
                echo '<div class="alert alert-success">✓ User created successfully!</div>';
                echo '<div class="alert alert-info">User ID: ' . $user->id . '</div>';
                echo '<div class="alert alert-info">Email: ' . $user->email . '</div>';
                echo '<div class="alert alert-info">Role: ' . $user->role . '</div>';
                
            } catch (Exception $e) {
                echo '<div class="alert alert-error">✗ User creation failed: ' . $e->getMessage() . '</div>';
            }
        }
    }
    
    if (isset($_POST['create_admin'])) {
        $name = $_POST['admin_name'] ?? 'Admin User';
        $email = $_POST['admin_email'] ?? 'admin@example.com';
        $password = $_POST['admin_password'] ?? 'password123';
        
        try {
            require_once $workingDir . '/vendor/autoload.php';
            $app = require_once $workingDir . '/bootstrap/app.php';
            
            $user = new \App\Models\User();
            $user->name = $name;
            $user->email = $email;
            $user->password = \Illuminate\Support\Facades\Hash::make($password);
            $user->role = 'admin';
            $user->email_verified_at = now();
            $user->save();
            
            echo '<div class="alert alert-success">✓ Admin user created successfully!</div>';
            echo '<div class="alert alert-info">Email: ' . $user->email . '</div>';
            echo '<div class="alert alert-info">Password: ' . $password . '</div>';
            echo '<div class="alert alert-warning">⚠ Please change the password after first login!</div>';
            
        } catch (Exception $e) {
            echo '<div class="alert alert-error">✗ Admin user creation failed: ' . $e->getMessage() . '</div>';
        }
    }
    
    if (isset($_POST['list_users'])) {
        try {
            require_once $workingDir . '/vendor/autoload.php';
            $app = require_once $workingDir . '/bootstrap/app.php';
            
            $users = \App\Models\User::all();
            
            if ($users->count() > 0) {
                echo '<h4>Existing Users</h4>';
                echo '<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">';
                echo '<tr style="background: #f8f9fa;">';
                echo '<th style="border: 1px solid #ddd; padding: 10px;">ID</th>';
                echo '<th style="border: 1px solid #ddd; padding: 10px;">Name</th>';
                echo '<th style="border: 1px solid #ddd; padding: 10px;">Email</th>';
                echo '<th style="border: 1px solid #ddd; padding: 10px;">Role</th>';
                echo '<th style="border: 1px solid #ddd; padding: 10px;">Created</th>';
                echo '</tr>';
                
                foreach ($users as $user) {
                    echo '<tr>';
                    echo '<td style="border: 1px solid #ddd; padding: 10px;">' . $user->id . '</td>';
                    echo '<td style="border: 1px solid #ddd; padding: 10px;">' . $user->name . '</td>';
                    echo '<td style="border: 1px solid #ddd; padding: 10px;">' . $user->email . '</td>';
                    echo '<td style="border: 1px solid #ddd; padding: 10px;">' . $user->role . '</td>';
                    echo '<td style="border: 1px solid #ddd; padding: 10px;">' . $user->created_at->format('Y-m-d H:i') . '</td>';
                    echo '</tr>';
                }
                
                echo '</table>';
            } else {
                echo '<div class="alert alert-info">No users found in the database.</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="alert alert-error">✗ Failed to list users: ' . $e->getMessage() . '</div>';
        }
    }
    
    echo '</div>';
}

// Get teams for dropdown
$teams = [];
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $app = require_once dirname(__DIR__) . '/bootstrap/app.php';
        $teams = \App\Models\Team::all();
    } catch (Exception $e) {
        // Teams not available
    }
}

echo '<div class="section">';
echo '<h3>Create New User</h3>';

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

echo '<div class="form-group">';
echo '<label>Team (Optional)</label>';
echo '<select name="team_id">';
echo '<option value="">No Team</option>';
foreach ($teams as $team) {
    echo '<option value="' . $team->id . '">' . $team->name . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="form-group">';
echo '<label>ClickUp User ID (Optional)</label>';
echo '<input type="text" name="clickup_user_id" placeholder="ClickUp user ID for integration">';
echo '</div>';

echo '<input type="submit" name="create_user" value="Create User" class="btn btn-success">';
echo '</form>';

echo '</div>';

echo '<div class="section">';
echo '<h3>Quick Admin Setup</h3>';
echo '<p>Create an admin user quickly for initial setup.</p>';

echo '<form method="post">';
echo '<div class="form-group">';
echo '<label>Admin Name</label>';
echo '<input type="text" name="admin_name" value="Admin User" placeholder="Admin User">';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Admin Email</label>';
echo '<input type="email" name="admin_email" value="admin@example.com" placeholder="admin@example.com">';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Admin Password</label>';
echo '<input type="password" name="admin_password" value="password123" placeholder="Enter admin password">';
echo '</div>';

echo '<input type="submit" name="create_admin" value="Create Admin User" class="btn btn-warning">';
echo '</form>';

echo '</div>';

echo '<div class="section">';
echo '<h3>List Existing Users</h3>';
echo '<p>View all users currently in the database.</p>';

echo '<form method="post">';
echo '<input type="submit" name="list_users" value="List Users" class="btn">';
echo '</form>';

echo '</div>';

echo '<div class="section">';
echo '<h3>User Roles Explained</h3>';
echo '<div class="grid">';

echo '<div class="card">';
echo '<h4>👤 User</h4>';
echo '<ul>';
echo '<li>Can clock in/out</li>';
echo '<li>Can track time on tasks</li>';
echo '<li>Can view own time entries</li>';
echo '<li>Can view assigned tasks</li>';
echo '</ul>';
echo '</div>';

echo '<div class="card">';
echo '<h4>👨‍💼 Manager</h4>';
echo '<ul>';
echo '<li>All User permissions</li>';
echo '<li>Can view team analytics</li>';
echo '<li>Can view team time entries</li>';
echo '<li>Can manage team members</li>';
echo '</ul>';
echo '</div>';

echo '<div class="card">';
echo '<h4>👑 Admin</h4>';
echo '<ul>';
echo '<li>All Manager permissions</li>';
echo '<li>Can create/edit users</li>';
echo '<li>Can manage teams</li>';
echo '<li>Can view all analytics</li>';
echo '<li>Can access ClickUp logs</li>';
echo '<li>Can export data</li>';
echo '</ul>';
echo '</div>';

echo '</div>';
echo '</div>';

echo '<div class="section">';
echo '<h3>Important Notes</h3>';
echo '<div class="alert alert-info">';
echo '<h4>Security Considerations</h4>';
echo '<ul>';
echo '<li>Always use strong passwords</li>';
echo '<li>Change default admin password after first login</li>';
echo '<li>Only create admin users for trusted personnel</li>';
echo '<li>Regularly review user access and permissions</li>';
echo '</ul>';
echo '</div>';

echo '<div class="alert alert-warning">';
echo '<h4>Database Requirements</h4>';
echo '<ul>';
echo '<li>Database must be set up and migrated</li>';
echo '<li>User table must exist (run migrations first)</li>';
echo '<li>Laravel application must be properly configured</li>';
echo '</ul>';
echo '</div>';
echo '</div>';

echo '</div>';
?>
