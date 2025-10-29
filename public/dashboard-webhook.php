<?php
// Webhook Management Tab Content
echo '<div id="webhook" class="tab-content">';
echo '<h2>🔗 ClickUp Webhook Management</h2>';

$workingDir = dirname(__DIR__);

// Bootstrap helper
if (!function_exists('bootstrapLaravel')) {
    function bootstrapLaravel(string $workingDir): void {
        static $booted = false;
        if ($booted) return;
        require_once $workingDir . '/vendor/autoload.php';
        $app = require_once $workingDir . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        $booted = true;
    }
}

// Check dependencies
$canUseLaravel = is_dir($workingDir . '/vendor') && file_exists($workingDir . '/vendor/autoload.php');

if (!$canUseLaravel) {
    echo '<div class="alert alert-error">⚠ Composer dependencies not installed. Please install them first using the Laravel tab.</div>';
    echo '</div>';
    return;
}

try {
    bootstrapLaravel($workingDir);
} catch (Exception $e) {
    echo '<div class="alert alert-error">✗ Failed to bootstrap Laravel: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
    return;
}

$clickUpToken = env('CLICKUP_API_TOKEN') ?: env('CLICKUP_TOKEN');
$teamId = env('CLICKUP_TEAM_ID');
$spaceId = env('CLICKUP_SPACE_ID');

if (!$clickUpToken) {
    echo '<div class="alert alert-warning">⚠ CLICKUP_API_TOKEN or CLICKUP_TOKEN not set in .env file.</div>';
}

// Handle form submissions
if ($_POST) {
    echo '<div class="section">';
    echo '<h3>Operation Results</h3>';
    
    if (isset($_POST['create_webhook'])) {
        $endpoint = $_POST['endpoint'] ?? '';
        $events = $_POST['events'] ?? ['taskCreated', 'taskUpdated', 'taskDeleted', 'taskAssigneeUpdated'];
        $status = $_POST['status'] ?? 'active';
        $webhookTeamId = $_POST['team_id'] ?? $teamId;
        $webhookSpaceId = $_POST['space_id'] ?? $spaceId;
        
        if (empty($endpoint)) {
            echo '<div class="alert alert-error">✗ Endpoint URL is required</div>';
        } elseif (!$clickUpToken) {
            echo '<div class="alert alert-error">✗ ClickUp API token not configured</div>';
        } elseif (!$webhookTeamId && !$webhookSpaceId) {
            echo '<div class="alert alert-error">✗ Team ID or Space ID is required</div>';
        } else {
            try {
                $service = app(\App\Services\ClickUpService::class);
                $targetId = $webhookTeamId ?: $webhookSpaceId;
                
                if ($webhookTeamId) {
                    $result = $service->createWebhook($targetId, $endpoint, $events, $status);
                } else {
                    // For space webhooks, we'd need to add that method or use team endpoint
                    echo '<div class="alert alert-warning">⚠ Space webhooks need team context. Please provide Team ID.</div>';
                    $result = ['error' => true];
                }
                
                if (isset($result['error']) && $result['error']) {
                    echo '<div class="alert alert-error">✗ Failed to create webhook: ' . htmlspecialchars($result['message'] ?? 'Unknown error') . '</div>';
                    if (isset($result['status'])) {
                        echo '<div class="alert alert-info">Status Code: ' . $result['status'] . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-success">✓ Webhook created successfully!</div>';
                    echo '<div class="alert alert-info">Webhook ID: ' . ($result['webhook']['id'] ?? 'N/A') . '</div>';
                    echo '<div class="alert alert-info">Endpoint: ' . htmlspecialchars($endpoint) . '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="alert alert-error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
    
    if (isset($_POST['update_webhook'])) {
        $webhookId = $_POST['webhook_id'] ?? '';
        $endpoint = $_POST['endpoint'] ?? null;
        $events = $_POST['events'] ?? null;
        $status = $_POST['status'] ?? null;
        $webhookTeamId = $_POST['team_id'] ?? $teamId;
        
        if (empty($webhookId)) {
            echo '<div class="alert alert-error">✗ Webhook ID is required</div>';
        } elseif (!$clickUpToken) {
            echo '<div class="alert alert-error">✗ ClickUp API token not configured</div>';
        } elseif (!$webhookTeamId) {
            echo '<div class="alert alert-error">✗ Team ID is required</div>';
        } else {
            try {
                $service = app(\App\Services\ClickUpService::class);
                $updateData = [];
                if ($endpoint !== null) $updateData['endpoint'] = $endpoint;
                if ($events !== null) $updateData['events'] = $events;
                if ($status !== null) $updateData['status'] = $status;
                
                $result = $service->updateWebhook($webhookTeamId, $webhookId, $updateData);
                
                if (isset($result['error']) && $result['error']) {
                    echo '<div class="alert alert-error">✗ Failed to update webhook: ' . htmlspecialchars($result['message'] ?? 'Unknown error') . '</div>';
                } else {
                    echo '<div class="alert alert-success">✓ Webhook updated successfully!</div>';
                }
            } catch (Exception $e) {
                echo '<div class="alert alert-error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
    
    if (isset($_POST['delete_webhook'])) {
        $webhookId = $_POST['webhook_id'] ?? '';
        $webhookTeamId = $_POST['team_id'] ?? $teamId;
        
        if (empty($webhookId)) {
            echo '<div class="alert alert-error">✗ Webhook ID is required</div>';
        } elseif (!$clickUpToken) {
            echo '<div class="alert alert-error">✗ ClickUp API token not configured</div>';
        } elseif (!$webhookTeamId) {
            echo '<div class="alert alert-error">✗ Team ID is required</div>';
        } else {
            try {
                $service = app(\App\Services\ClickUpService::class);
                $success = $service->deleteWebhook($webhookTeamId, $webhookId);
                
                if ($success) {
                    echo '<div class="alert alert-success">✓ Webhook deleted successfully!</div>';
                } else {
                    echo '<div class="alert alert-error">✗ Failed to delete webhook</div>';
                }
            } catch (Exception $e) {
                echo '<div class="alert alert-error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
    
    echo '</div>';
}

// Load existing webhooks
$webhooks = [];
if ($clickUpToken && $teamId) {
    try {
        $service = app(\App\Services\ClickUpService::class);
        $webhooks = $service->listTeamWebhooks($teamId);
    } catch (Exception $e) {
        echo '<div class="alert alert-warning">⚠ Failed to load webhooks: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Configuration Info
echo '<div class="section">';
echo '<h3>Configuration</h3>';
echo '<div class="card">';
echo '<p><strong>ClickUp API Token:</strong> ' . ($clickUpToken ? '✓ Configured' : '✗ Not set') . '</p>';
echo '<p><strong>Team ID:</strong> ' . ($teamId ? htmlspecialchars($teamId) : 'Not set') . '</p>';
echo '<p><strong>Space ID:</strong> ' . ($spaceId ? htmlspecialchars($spaceId) : 'Not set') . '</p>';
echo '<p><strong>Production URL:</strong> https://timetracker.flownly.com/api/integrations/clickup/webhook</p>';
echo '</div>';
echo '</div>';

// Active Webhooks List
echo '<div class="section">';
echo '<h3>Active ClickUp Webhooks</h3>';

if (empty($webhooks)) {
    echo '<div class="alert alert-info">No webhooks found. Create one below.</div>';
} else {
    echo '<div class="card">';
    echo '<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">';
    echo '<thead><tr style="background: #f8f9fa;">';
    echo '<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">ID</th>';
    echo '<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Endpoint</th>';
    echo '<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Events</th>';
    echo '<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Status</th>';
    echo '<th style="border: 1px solid #ddd; padding: 10px; text-align: left;">Actions</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($webhooks as $webhook) {
        $status = $webhook['status'] ?? 'unknown';
        $statusClass = $status === 'active' ? 'status-success' : 'status-error';
        $events = is_array($webhook['events'] ?? []) ? implode(', ', $webhook['events']) : 'N/A';
        
        echo '<tr>';
        echo '<td style="border: 1px solid #ddd; padding: 10px;">' . htmlspecialchars($webhook['id'] ?? 'N/A') . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 10px; word-break: break-all; max-width: 300px;">' . htmlspecialchars($webhook['endpoint'] ?? 'N/A') . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 10px;">' . htmlspecialchars($events) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 10px;"><span class="status-indicator ' . $statusClass . '"></span>' . htmlspecialchars($status) . '</td>';
        echo '<td style="border: 1px solid #ddd; padding: 10px;">';
        echo '<form method="post" style="display: inline;" onsubmit="return confirm(\'Are you sure?\');">';
        echo '<input type="hidden" name="webhook_id" value="' . htmlspecialchars($webhook['id'] ?? '') . '">';
        echo '<input type="hidden" name="team_id" value="' . htmlspecialchars($teamId ?? '') . '">';
        echo '<button type="submit" name="delete_webhook" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;">Delete</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</div>';

// Create Webhook Form
echo '<div class="section">';
echo '<h3>Create New Webhook</h3>';

echo '<form method="post">';
echo '<div class="form-group">';
echo '<label>Endpoint URL *</label>';
echo '<input type="text" name="endpoint" value="https://timetracker.flownly.com/api/integrations/clickup/webhook" required placeholder="https://timetracker.flownly.com/api/integrations/clickup/webhook">';
echo '<small style="color: #666;">Full URL where ClickUp should send webhook events</small>';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Team ID *</label>';
echo '<input type="text" name="team_id" value="' . htmlspecialchars($teamId ?? '') . '" required placeholder="ClickUp Team ID">';
echo '<small style="color: #666;">Your ClickUp Team ID (from .env or enter manually)</small>';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Events *</label>';
echo '<div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 5px;">';
$defaultEvents = ['taskCreated', 'taskUpdated', 'taskDeleted', 'taskAssigneeUpdated'];
foreach ($defaultEvents as $event) {
    $checked = in_array($event, $defaultEvents) ? 'checked' : '';
    echo '<label style="display: flex; align-items: center; gap: 5px;">';
    echo '<input type="checkbox" name="events[]" value="' . $event . '" ' . $checked . '>';
    echo '<span>' . $event . '</span>';
    echo '</label>';
}
echo '</div>';
echo '</div>';

echo '<div class="form-group">';
echo '<label>Status</label>';
echo '<select name="status">';
echo '<option value="active" selected>Active</option>';
echo '<option value="suspended">Suspended</option>';
echo '</select>';
echo '</div>';

echo '<button type="submit" name="create_webhook" class="btn btn-success">Create Webhook</button>';
echo '</form>';

echo '</div>';

// Update Webhook Form (for reference, can expand later)
echo '<div class="section">';
echo '<h3>Update Webhook</h3>';
echo '<p>To update a webhook, delete the old one and create a new one with the updated settings. Or contact support for bulk updates.</p>';
echo '</div>';

echo '</div>';
?>

