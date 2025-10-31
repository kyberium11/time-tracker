<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use App\Services\ClickUpService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Return tasks assigned to the authenticated user.
     */
    public function myTasks()
    {
        $tasks = Task::where('user_id', Auth::id())
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'title', 'status', 'clickup_task_id']);

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
}


