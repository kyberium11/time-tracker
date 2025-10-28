<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClickUpWebhookLog;
use App\Services\ClickUpService;
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
        return response()->json([
            'webhooks' => $webhooks,
            'logs' => $logs,
        ]);
    }
}


