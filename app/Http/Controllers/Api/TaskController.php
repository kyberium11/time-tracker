<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use App\Services\ClickUpService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\User;

class TaskController extends Controller
{
    /**
     * Return tasks assigned to the authenticated user.
     */
    public function myTasks()
    {
        $tasks = Task::where('user_id', Auth::id())
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'title', 'status', 'priority', 'due_date', 'clickup_task_id', 'estimated_time']);

        return response()->json($tasks);
    }

    /**
     * Get or create the built-in Break task for the authenticated user.
     */
    public function myBreakTask()
    {
        $task = Task::firstOrCreate([
            'user_id' => Auth::id(),
            'title' => 'Break',
        ], [
            'description' => 'Built-in break task',
            'status' => 'active',
        ]);

        return response()->json($task);
    }

    /**
     * Sync a task with ClickUp and return fresh details.
     */
    public function sync(string $id, ClickUpService $clickUp)
    {
        $task = Task::findOrFail($id);
        if (!$task->clickup_task_id) {
            return response()->json($task);
        }
        $remote = $clickUp->getTask($task->clickup_task_id);
        if ($remote) {
            $task->update([
                'title' => (string) data_get($remote, 'name', $task->title),
                'description' => (string) data_get($remote, 'text_content', $task->description),
                'status' => (string) data_get($remote, 'status.status', $task->status),
                'priority' => (string) (
                    data_get($remote, 'priority.label')
                    ?: data_get($remote, 'priority.priority')
                    ?: data_get($remote, 'priority')
                    ?: $task->priority
                ),
                'clickup_parent_id' => (string) (data_get($remote, 'parent') ?: $task->clickup_parent_id),
                'due_date' => ($ms = data_get($remote, 'due_date')) ? Carbon::createFromTimestampMs((int) $ms) : $task->due_date,
            ]);
        }
        return response()->json(['task' => $task, 'clickup' => $remote]);
    }

    /**
     * Update local task status and sync to ClickUp if linked.
     */
    public function updateStatus(string $id, Request $request, ClickUpService $clickUp)
    {
        $task = Task::where('user_id', Auth::id())->findOrFail($id);
        $status = (string) $request->input('status', 'complete');

        // Update local first
        $task->status = $status;
        $task->save();

        // Push to ClickUp if linked
        if ($task->clickup_task_id) {
            $clickUp->updateTaskStatus($task->clickup_task_id, $status);
        }

        return response()->json(['ok' => true, 'task' => $task]);
    }

    /**
     * Manually sync the authenticated user's tasks from ClickUp.
     */
    public function syncMyClickUpTasks(ClickUpService $clickUp)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $teamId = (string) (env('CLICKUP_TEAM_ID') ?? '');
        if ($teamId === '') {
            return response()->json(['error' => 'CLICKUP_TEAM_ID is not configured'], 400);
        }

        $assigneeId = $user->clickup_user_id ? (string) $user->clickup_user_id : null;
        $assigneeEmail = !$assigneeId ? (string) $user->email : null;

        $tasks = $clickUp->listTeamTasksByAssignee($teamId, $assigneeId, $assigneeEmail);

        $upserted = 0;
        foreach ($tasks as $t) {
            $taskId = (string) (data_get($t, 'id') ?? '');
            if ($taskId === '') { continue; }

            $clickupName = (string) (data_get($t, 'name') ?: ('Task ' . $taskId));
            $clickupUrl = (string) (data_get($t, 'url') ?: ('https://app.clickup.com/t/' . $taskId));
            $displayTitle = trim($clickupName . ' - ' . $clickupUrl);

            Task::updateOrCreate(
                ['clickup_task_id' => $taskId],
                [
                    'user_id' => $user->id,
                    'title' => $displayTitle,
                    'description' => (string) data_get($t, 'text_content'),
                    'status' => (string) data_get($t, 'status.status'),
                    'priority' => (string) (
                        data_get($t, 'priority.label')
                        ?: data_get($t, 'priority.priority')
                        ?: data_get($t, 'priority')
                        ?: null
                    ),
                    'clickup_parent_id' => (string) (data_get($t, 'parent') ?: null),
                    'due_date' => ($ms = data_get($t, 'due_date')) ? Carbon::createFromTimestampMs((int)$ms) : null,
                    'estimated_time' => ($est = data_get($t, 'time_estimate')) ? (int)$est : null,
                ]
            );
            $upserted++;
        }

        return response()->json(['ok' => true, 'count' => $upserted]);
    }
}


