<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\ClickUpWebhookLog;
use App\Services\ClickUpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ClickUpWebhookController extends Controller
{
    public function __construct(private readonly ClickUpService $clickUp)
    {
    }

    public function handle(Request $request)
    {
        $raw = $request->getContent();
        $sig = $request->header('x-signature') ?? $request->header('X-Signature');

        // Optional signature verification if configured
        if (!empty(config('services.clickup.signing_secret'))) {
            if (!$this->clickUp->verifySignature($raw, $sig)) {
                // If allowed (e.g. local dev), log and continue instead of rejecting
                if (env('CLICKUP_ALLOW_UNVERIFIED', app()->environment('local'))) {
                    ClickUpWebhookLog::create([
                        'event' => data_get(json_decode($raw, true), 'event'),
                        'task_id' => data_get(json_decode($raw, true), 'task.id')
                            ?? data_get(json_decode($raw, true), 'data.task_id')
                            ?? data_get(json_decode($raw, true), 'id'),
                        'status_code' => 401,
                        'message' => 'Invalid signature (proceeding due to CLICKUP_ALLOW_UNVERIFIED)',
                        'payload' => json_decode($raw, true),
                    ]);
                } else {
                    return response()->json(['message' => 'Invalid signature'], 401);
                }
            }
        }

        $payload = $request->all();
        $event = data_get($payload, 'event');
        // Task id may be located under multiple keys depending on event type
        $taskId = data_get($payload, 'task.id')
            ?? data_get($payload, 'data.task_id')
            ?? data_get($payload, 'task_id')
            ?? data_get($payload, 'id');

        if (!$taskId) {
            ClickUpWebhookLog::create([
                'event' => $event,
                'task_id' => null,
                'status_code' => 200,
                'message' => 'No task id in payload',
                'payload' => $payload,
            ]);
            return response()->json(['message' => 'No task id'], 200);
        }

        // Be efficient: make at most one ClickUp API call per webhook.
        // For deletions, we can't fetch the task reliably; just log and exit.
        if ($event === 'taskDeleted') {
            ClickUpWebhookLog::create([
                'event' => $event,
                'task_id' => (string) $taskId,
                'status_code' => 200,
                'message' => 'Task deleted event received (skipping fetch)',
                'payload' => $payload,
            ]);
            return response()->json(['message' => 'ok (deleted)'], 200);
        }

        // Fetch latest task details from ClickUp API once (no retries)
        $task = $this->clickUp->getTask($taskId);
        if (empty($task)) {
            ClickUpWebhookLog::create([
                'event' => $event,
                'task_id' => (string) $taskId,
                'status_code' => 200,
                'message' => 'ClickUp API returned no task data',
                'payload' => $payload,
            ]);
            return response()->json(['message' => 'No task data'], 200);
        }

        $assignees = data_get($task, 'assignees', []);
        $userId = null;
        foreach ($assignees as $assignee) {
            $assigneeId = (string)($assignee['id'] ?? '');
            $assigneeEmail = (string)($assignee['email'] ?? '');
            $user = null;
            if ($assigneeId !== '') {
                $user = User::where('clickup_user_id', $assigneeId)->first();
            }
            if (!$user && $assigneeEmail !== '') {
                $user = User::where('email', $assigneeEmail)->first();
            }
            if ($user) {
                $userId = $user->id;
                break;
            }
        }

        // Fallback: infer assignee from webhook payload when task API lacks assignees
        if (!$userId) {
            $historyItems = data_get($payload, 'history_items', []);
            foreach ($historyItems as $item) {
                $field = data_get($item, 'field');
                if (in_array($field, ['assignee_add', 'assignee_set'])) {
                    $aid = (string) data_get($item, 'after.id');
                    $aemail = (string) data_get($item, 'after.email');
                    $user = null;
                    if ($aid !== '') {
                        $user = User::where('clickup_user_id', $aid)->first();
                    }
                    if (!$user && $aemail !== '') {
                        $user = User::where('email', $aemail)->first();
                    }
                    if ($user) {
                        $userId = $user->id;
                        break;
                    }
                }
            }
        }

        $clickupName = (string) (data_get($task, 'name') ?: ('Task ' . $taskId));
        $clickupUrl = (string) (data_get($task, 'url') ?: ('https://app.clickup.com/t/' . $taskId));
        $displayTitle = trim($clickupName . ' - ' . $clickupUrl);

        $local = Task::updateOrCreate(
            ['clickup_task_id' => (string) data_get($task, 'id')],
            [
                'user_id' => $userId,
                'title' => $displayTitle,
                'description' => (string) data_get($task, 'text_content'),
                'status' => (string) data_get($task, 'status.status'),
                'clickup_parent_id' => (string) (data_get($task, 'parent') ?: null),
                'due_date' => ($ms = data_get($task, 'due_date')) ? Carbon::createFromTimestampMs((int)$ms) : null,
            ]
        );

        ClickUpWebhookLog::create([
            'event' => $event,
            'task_id' => (string) $taskId,
            'status_code' => 200,
            'message' => 'Upserted task id '.$local->id,
            'payload' => $payload,
        ]);

        return response()->json(['ok' => true]);
    }
}


