<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClickUpService
{
    public function __construct(
        private readonly ?string $apiToken,
        private readonly ?string $signingSecret,
    ) {}

    public function verifySignature(string $payload, ?string $signatureHeader): bool
    {
        if (!$this->signingSecret) {
            // If no signing secret configured, accept
            return true;
        }

        if (!$signatureHeader) {
            return false;
        }

        $calculated = hash_hmac('sha256', $payload, $this->signingSecret);
        return hash_equals($calculated, $signatureHeader);
    }

    public function getTask(string $taskId): array
    {
        $headers = [
            'Authorization' => (string) $this->apiToken,
        ];
        $base = 'https://api.clickup.com/api/v2/task/' . $taskId;

        // Prefer using custom_task_ids for short IDs (alpha-numeric) and provide team_id
        $teamId = env('CLICKUP_TEAM_ID');
        $query = [];
        if ($teamId) {
            $query['team_id'] = $teamId;
        }
        $query['custom_task_ids'] = 'true';

        $response = Http::withHeaders($headers)->get($base, $query);

        if ($response->failed()) {
            // Fallback: try without custom_task_ids
            $fallback = Http::withHeaders($headers)->get($base);
            if ($fallback->failed()) {
                Log::warning('ClickUp getTask failed', [
                    'taskId' => $taskId,
                    'status' => $fallback->status(),
                    'body' => $fallback->body(),
                ]);
                return ['__error' => [
                    'status' => $fallback->status(),
                    'body' => (string) $fallback->body(),
                ]];
            }
            return $fallback->json() ?? [];
        }

        return $response->json() ?? [];
    }

    public function listTeamWebhooks(string $teamId): array
    {
        $response = Http::withHeaders([
                'Authorization' => (string) $this->apiToken,
            ])->get('https://api.clickup.com/api/v2/team/' . $teamId . '/webhook');

        if ($response->failed()) {
            Log::warning('ClickUp list webhooks failed', ['teamId' => $teamId, 'status' => $response->status(), 'body' => $response->body()]);
            return [];
        }

        return $response->json()['webhooks'] ?? [];
    }

    public function createTimeEntry(string $teamId, array $payload): array
    {
        $response = Http::withHeaders([
                'Authorization' => (string) $this->apiToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.clickup.com/api/v2/team/' . $teamId . '/time_entries', $payload);

        if ($response->failed()) {
            Log::warning('ClickUp create time entry failed', [
                'teamId' => $teamId,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);
            return [];
        }

        return $response->json() ?? [];
    }
}


