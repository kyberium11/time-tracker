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

        try {
            // Keep the webhook response snappy: small timeout, no retries
            $response = Http::withHeaders($headers)
                ->timeout(2)
                ->get($base, $query);

            if ($response->failed()) {
                Log::warning('ClickUp getTask failed', [
                    'taskId' => $taskId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['__error' => [
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]];
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('ClickUp getTask exception', [
                'taskId' => $taskId,
                'message' => $e->getMessage(),
            ]);
            return ['__error' => [
                'status' => 0,
                'body' => 'exception: ' . $e->getMessage(),
            ]];
        }
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

    public function createWebhook(string $teamId, string $endpoint, array $events = null, string $status = 'active'): array
    {
        $events = $events ?? ['taskCreated', 'taskUpdated', 'taskDeleted', 'taskAssigneeUpdated'];
        
        $body = [
            'endpoint' => $endpoint,
            'events' => $events,
            'status' => $status,
        ];

        $response = Http::asJson()
            ->withHeaders([
                'Authorization' => (string) $this->apiToken,
                'Accept' => 'application/json',
            ])->post('https://api.clickup.com/api/v2/team/' . $teamId . '/webhook', $body);

        if ($response->failed()) {
            Log::warning('ClickUp create webhook failed', [
                'teamId' => $teamId,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return ['error' => true, 'message' => $response->body(), 'status' => $response->status()];
        }

        return $response->json() ?? [];
    }

    public function updateWebhook(string $teamId, string $webhookId, array $data): array
    {
        $response = Http::asJson()
            ->withHeaders([
                'Authorization' => (string) $this->apiToken,
                'Accept' => 'application/json',
            ])->put('https://api.clickup.com/api/v2/team/' . $teamId . '/webhook/' . $webhookId, $data);

        if ($response->failed()) {
            Log::warning('ClickUp update webhook failed', [
                'teamId' => $teamId,
                'webhookId' => $webhookId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return ['error' => true, 'message' => $response->body(), 'status' => $response->status()];
        }

        return $response->json() ?? [];
    }

    public function deleteWebhook(string $teamId, string $webhookId): array
    {
        // Try both endpoint formats - ClickUp API may use /webhook/{id} without team prefix for DELETE
        $urls = [
            'https://api.clickup.com/api/v2/webhook/' . $webhookId, // Format 1: Direct webhook endpoint
            'https://api.clickup.com/api/v2/team/' . $teamId . '/webhook/' . $webhookId, // Format 2: Team-specific endpoint
        ];
        
        foreach ($urls as $url) {
            $response = Http::withHeaders([
                    'Authorization' => (string) $this->apiToken,
                    'Accept' => 'application/json',
                ])->delete($url);

            $statusCode = $response->status();
            $body = $response->body();
            
            // If successful (200, 204, or 202), return success
            if ($statusCode === 200 || $statusCode === 204 || $statusCode === 202) {
                return ['error' => false, 'success' => true, 'status' => $statusCode];
            }
            
            // If 404, try next URL format
            if ($statusCode === 404) {
                continue;
            }
            
            // For other errors, log and return error
            if ($response->failed()) {
                Log::warning('ClickUp delete webhook failed', [
                    'teamId' => $teamId,
                    'webhookId' => $webhookId,
                    'url' => $url,
                    'status' => $statusCode,
                    'body' => $body,
                ]);
                // If this was the last URL to try, return error
                if ($url === end($urls)) {
                    return [
                        'error' => true,
                        'message' => $body ?: 'HTTP ' . $statusCode,
                        'status' => $statusCode,
                    ];
                }
            }
        }
        
        // If all attempts failed with 404, webhook might not exist or be in wrong format
        return [
            'error' => true,
            'message' => 'Webhook not found. It may have already been deleted or the endpoint format is incorrect.',
            'status' => 404,
        ];
    }

    public function createTimeEntry(string $teamId, array $payload): array
    {
        try {
            $response = Http::withHeaders([
                    'Authorization' => (string) $this->apiToken,
                    'Content-Type' => 'application/json',
                ])->timeout(3)
                ->post('https://api.clickup.com/api/v2/team/' . $teamId . '/time_entries', $payload);

            if ($response->failed()) {
                Log::warning('ClickUp create time entry failed', [
                    'teamId' => $teamId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'payload' => $payload,
                ]);
                return [
                    'error' => true,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ];
            }

            return $response->json() ?? ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('ClickUp create time entry exception', [
                'teamId' => $teamId,
                'message' => $e->getMessage(),
                'payload' => $payload,
            ]);
            return [
                'error' => true,
                'status' => 0,
                'body' => 'exception: ' . $e->getMessage(),
            ];
        }
    }

    public function updateTaskStatus(string $taskId, string $status): array
    {
        $headers = [
            'Authorization' => (string) $this->apiToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $base = 'https://api.clickup.com/api/v2/task/' . $taskId;
        $teamId = env('CLICKUP_TEAM_ID');
        $query = [ 'custom_task_ids' => 'true' ];
        if ($teamId) { $query['team_id'] = $teamId; }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(4)
                ->put($base, [ 'status' => $status ], $query);

            if ($response->failed()) {
                Log::warning('ClickUp update task status failed', [
                    'taskId' => $taskId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [ 'error' => true, 'status' => $response->status(), 'body' => (string) $response->body() ];
            }
            return $response->json() ?? ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('ClickUp update task status exception', [
                'taskId' => $taskId,
                'message' => $e->getMessage(),
            ]);
            return [ 'error' => true, 'status' => 0, 'body' => 'exception: '.$e->getMessage() ];
        }
    }

    public function updateTaskCustomField(string $taskId, string $fieldId, $value): array
    {
        $headers = [
            'Authorization' => (string) $this->apiToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $base = 'https://api.clickup.com/api/v2/task/' . $taskId . '/field/' . $fieldId;
        $teamId = env('CLICKUP_TEAM_ID');
        $query = [ 'custom_task_ids' => 'true' ];
        if ($teamId) { $query['team_id'] = $teamId; }

        try {
            $payload = [ 'value' => $value ];
            $response = Http::withHeaders($headers)
                ->timeout(4)
                ->put($base, $payload, $query);

            if ($response->failed()) {
                Log::warning('ClickUp update custom field failed', [
                    'taskId' => $taskId,
                    'fieldId' => $fieldId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [ 'error' => true, 'status' => $response->status(), 'body' => (string) $response->body() ];
            }
            return $response->json() ?? ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('ClickUp update custom field exception', [
                'taskId' => $taskId,
                'fieldId' => $fieldId,
                'message' => $e->getMessage(),
            ]);
            return [ 'error' => true, 'status' => 0, 'body' => 'exception: '.$e->getMessage() ];
        }
    }

    public function addTaskComment(string $taskId, string $text): array
    {
        $headers = [
            'Authorization' => (string) $this->apiToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $base = 'https://api.clickup.com/api/v2/task/' . $taskId . '/comment';
        $teamId = env('CLICKUP_TEAM_ID');
        $query = [ 'custom_task_ids' => 'true' ];
        if ($teamId) { $query['team_id'] = $teamId; }

        try {
            $payload = [ 'comment_text' => $text ];
            $response = Http::withHeaders($headers)
                ->timeout(4)
                ->post($base, $payload, $query);

            if ($response->failed()) {
                Log::warning('ClickUp add task comment failed', [
                    'taskId' => $taskId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [ 'error' => true, 'status' => $response->status(), 'body' => (string) $response->body() ];
            }
            return $response->json() ?? ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('ClickUp add task comment exception', [
                'taskId' => $taskId,
                'message' => $e->getMessage(),
            ]);
            return [ 'error' => true, 'status' => 0, 'body' => 'exception: '.$e->getMessage() ];
        }
    }
}


