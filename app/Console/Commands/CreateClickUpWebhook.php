<?php

namespace App\Console\Commands;

use App\Support\ClickUpConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CreateClickUpWebhook extends Command
{
    protected $signature = 'clickup:webhook:create {endpoint?} {--team_id=} {--space_id=} {--token=}';
    protected $description = 'Create a ClickUp webhook for tasks events.';

    public function handle(): int
    {
        $apiToken = $this->option('token')
            ?: config('clickup.api_token');
        if (!$apiToken) {
            $this->error('CLICKUP_API_TOKEN not set');
            return self::FAILURE;
        }

        $endpoint = $this->argument('endpoint') ?: rtrim(config('app.url'), '/') . '/api/integrations/clickup/webhook';
        $teamId = $this->option('team_id') ?: ClickUpConfig::teamId();
        $spaceId = $this->option('space_id') ?: config('clickup.space_id');
        $target = $teamId ? ["type" => "team", "id" => $teamId] : ($spaceId ? ["type" => "space", "id" => $spaceId] : null);
        if (!$target) {
            $this->error('Provide --team_id/CLICKUP_TEAM_ID (recommended) or --space_id/CLICKUP_SPACE_ID');
            return self::FAILURE;
        }

        $body = [
            'endpoint' => $endpoint,
            'events' => ['taskCreated','taskUpdated','taskDeleted','taskAssigneeUpdated'],
            'status' => 'active',
        ];

        $url = $target['type'] === 'team'
            ? "https://api.clickup.com/api/v2/team/{$target['id']}/webhook"
            : "https://api.clickup.com/api/v2/space/{$target['id']}/webhook";

        $resp = Http::asJson()
            ->withHeaders([
                'Authorization' => (string) $apiToken,
                'Accept' => 'application/json',
            ])->post($url, $body);

        if ($resp->failed()) {
            $this->error('Failed to create webhook: ' . $resp->status() . ' ' . $resp->body());
            return self::FAILURE;
        }

        $this->info('Webhook created: ' . json_encode($resp->json()));
        return self::SUCCESS;
    }
}


