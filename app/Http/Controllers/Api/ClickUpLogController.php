<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClickUpWebhookLog;
use App\Services\ClickUpService;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Config;

class ClickUpLogController extends Controller
{
    public function index(ClickUpService $clickUp)
    {
        $logs = ClickUpWebhookLog::latest()->limit(100)->get();
        $teamId = env('CLICKUP_TEAM_ID');
        $webhooks = [];
        if ($teamId) {
            $webhooks = $clickUp->listTeamWebhooks($teamId);
        }
        // Include ClickUp time entry sync logs for easier debugging
        $timeEntryLogs = UserActivityLog::with('user')
            ->whereIn('action', ['clickup_time_entry_error', 'clickup_time_entry_synced'])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'webhooks' => $webhooks,
            'logs' => $logs,
            'time_entry_logs' => $timeEntryLogs,
        ]);
    }
}


