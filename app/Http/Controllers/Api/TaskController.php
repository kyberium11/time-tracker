<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use App\Services\ClickUpService;
use App\Support\ClickUpConfig;
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
        $statusOrdering = <<<SQL
            CASE
                WHEN LOWER(status) = 'in progress' THEN 0
                WHEN LOWER(status) = 'to do' THEN 1
                WHEN LOWER(status) = 'complete' THEN 3
                ELSE 2
            END
        SQL;

        $tasks = Task::where('user_id', Auth::id())
            ->with(['parentTask' => function ($query) {
                $query->select('id', 'title', 'clickup_task_id');
            }])
            ->orderByRaw($statusOrdering)
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'title', 'status', 'priority', 'due_date', 'clickup_task_id', 'clickup_parent_id', 'estimated_time', 'clickup_list_name']);

        // Add parent task name to each task
        $tasks->transform(function ($task) {
            $taskArray = $task->toArray();
            $taskArray['parent_task_name'] = $task->parentTask ? $task->parentTask->title : null;
            return $taskArray;
        });

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
                'estimated_time' => $clickUp->resolveTaskEstimate($remote, false) ?? $task->estimated_time,
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
     * List ClickUp spaces available for the authenticated user based on configured teams.
     */
    public function myClickUpSpaces(ClickUpService $clickUp)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $teamIds = ClickUpConfig::teamIds();
        if (count($teamIds) === 0) {
            return response()->json(['spaces' => []]);
        }

        $spaces = [];
        $seen = [];
        $clickUpUserId = $user->clickup_user_id ? (string) $user->clickup_user_id : null;
        $clickUpEmail = $user->email ? (string) $user->email : null;

        foreach ($teamIds as $teamId) {
            try {
                $items = $clickUp->listTeamSpaces((string) $teamId);
            } catch (\Throwable $e) {
                \Log::error('Failed to fetch ClickUp spaces', [
                    'userId' => $user->id,
                    'teamId' => $teamId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($items as $space) {
                $spaceId = (string) (data_get($space, 'id') ?? '');
                if ($spaceId === '' || isset($seen[$spaceId])) {
                    continue;
                }

                if (!$this->clickUpSpaceIncludesUser($space, $clickUpUserId, $clickUpEmail, $clickUp)) {
                    continue;
                }

                $seen[$spaceId] = true;

                $spaces[] = [
                    'id' => $spaceId,
                    'name' => (string) (data_get($space, 'name') ?? ('Space ' . $spaceId)),
                    'team_id' => (string) $teamId,
                    'team_name' => (string) (data_get($space, 'team.name') ?? null),
                    'color' => data_get($space, 'color'),
                ];
            }
        }

        return response()->json(['spaces' => $spaces]);
    }

    /**
     * Get space information and check if user is a member.
     */
    public function getClickUpSpaceInfo(string $spaceId, ClickUpService $clickUp)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $space = $clickUp->getSpace($spaceId);
            if (!$space) {
                return response()->json(['error' => 'Space not found'], 404);
            }

            $clickUpUserId = $user->clickup_user_id ? (string) $user->clickup_user_id : null;
            $clickUpEmail = $user->email ? (string) $user->email : null;

            // Check if user is a member
            $isMember = $this->clickUpSpaceIncludesUser($space, $clickUpUserId, $clickUpEmail, $clickUp);

            return response()->json([
                'space_id' => $spaceId,
                'name' => (string) (data_get($space, 'name') ?? ('Space ' . $spaceId)),
                'is_member' => $isMember,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to get ClickUp space info', [
                'userId' => $user->id,
                'spaceId' => $spaceId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to get space information: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Sync tasks from a single ClickUp space.
     */
    public function syncClickUpSpace(string $spaceId, ClickUpService $clickUp)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $assigneeId = $user->clickup_user_id ? (string) $user->clickup_user_id : null;
        $assigneeEmail = !$assigneeId ? (string) $user->email : null;

        $allTasks = [];
        $seen = [];

        $collectTasks = function (array $tasksChunk) use (&$allTasks, &$seen) {
            foreach ($tasksChunk as $t) {
                $tid = (string) (data_get($t, 'id') ?? '');
                if ($tid !== '' && !isset($seen[$tid])) {
                    $seen[$tid] = true;
                    $allTasks[] = $t;
                }
            }
        };

        try {
            // When syncing a specific space, fetch all tasks within that space regardless of assignee.
            $tasksChunk = $clickUp->listSpaceTasksByAssignee((string) $spaceId, null, null, []);
            $collectTasks($tasksChunk);
        } catch (\Throwable $e) {
            \Log::error('Failed to fetch tasks from ClickUp space', [
                'spaceId' => $spaceId,
                'assigneeId' => $assigneeId,
                'assigneeEmail' => $assigneeEmail,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to fetch tasks: ' . $e->getMessage()], 500);
        }

        $tasks = $allTasks;

        $created = [];
        $updated = [];
        $unchanged = 0;
        $skipped = [];

        foreach ($tasks as $t) {
            $taskId = (string) (data_get($t, 'id') ?? '');
            if ($taskId === '') {
                $skipped[] = [
                    'id' => '(unknown)',
                    'reason' => 'Missing task ID from ClickUp payload',
                ];
                continue;
            }

            $clickupName = (string) (data_get($t, 'name') ?: ('Task ' . $taskId));
            $clickupUrl = (string) (data_get($t, 'url') ?: ('https://app.clickup.com/t/' . $taskId));
            $displayTitle = trim($clickupName . ' - ' . $clickupUrl);
            $priority = (string) (
                data_get($t, 'priority.label')
                ?: data_get($t, 'priority.priority')
                ?: data_get($t, 'priority')
                ?: ''
            ) ?: null;
            $dueDate = ($ms = data_get($t, 'due_date')) ? Carbon::createFromTimestampMs((int) $ms) : null;
            
            $listId = (string) (data_get($t, 'list.id') ?? '');
            $listName = (string) (data_get($t, 'list.name') ?? '');

            $task = Task::firstOrNew(['clickup_task_id' => $taskId]);
            $wasCreated = !$task->exists;

            $estimatedTime = null;
            if (data_get($t, 'time_estimate')) {
                $estimatedTime = (int) data_get($t, 'time_estimate');
            } elseif (data_get($t, 'time_estimates.total_estimate')) {
                $estimatedTime = (int) data_get($t, 'time_estimates.total_estimate');
            } elseif (data_get($t, 'time_estimates.total_estimated')) {
                $estimatedTime = (int) data_get($t, 'time_estimates.total_estimated');
            }

            $task->fill([
                'user_id' => $user->id,
                'title' => $displayTitle,
                'description' => (string) data_get($t, 'text_content'),
                'status' => (string) data_get($t, 'status.status'),
                'priority' => $priority,
                'clickup_parent_id' => (string) (data_get($t, 'parent') ?: null),
                'clickup_list_id' => $listId ?: null,
                'clickup_list_name' => $listName ?: null,
                'due_date' => $dueDate,
                'estimated_time' => $estimatedTime,
            ]);

            $dirty = $task->isDirty();
            $task->save();

            $taskPayload = [
                'id' => $taskId,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date ? $task->due_date->toIso8601String() : null,
            ];

            if ($wasCreated) {
                $created[] = $taskPayload;
            } elseif ($dirty) {
                $updated[] = $taskPayload;
            } else {
                $unchanged++;
            }
        }

        $summary = [
            'total' => count($created) + count($updated) + $unchanged,
            'created' => count($created),
            'updated' => count($updated),
            'unchanged' => $unchanged,
            'skipped' => count($skipped),
        ];

        return response()->json([
            'ok' => true,
            'summary' => $summary,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'space_id' => $spaceId,
        ]);
    }

    /**
     * Get configured space IDs for sequential syncing.
     */
    public function getConfiguredSpaceIds()
    {
        $spaceIds = ClickUpConfig::spaceIds();
        return response()->json(['space_ids' => $spaceIds]);
    }

    /**
     * Manually sync the authenticated user's tasks from ClickUp.
     */
    public function syncMyClickUpTasks(Request $request, ClickUpService $clickUp)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $teamIds = ClickUpConfig::teamIds();
        if (count($teamIds) === 0) {
            return response()->json(['error' => 'CLICKUP_TEAM_ID or CLICKUP_TEAM_IDS is not configured'], 400);
        }

        $assigneeId = $user->clickup_user_id ? (string) $user->clickup_user_id : null;
        $assigneeEmail = !$assigneeId ? (string) $user->email : null;

        $spaceIdsInput = $this->sanitizeSpaceIds($request->input('space_ids', []));
        $spaceRestricted = count($spaceIdsInput) > 0;
        $enforceAssigneeFilter = !$spaceRestricted;

        // Fetch tasks across all configured scopes (teams and/or spaces), merge/deduplicate.
        $allTasks = [];
        $seen = [];

        $collectTasks = function (array $tasksChunk) use (&$allTasks, &$seen) {
            foreach ($tasksChunk as $t) {
                $tid = (string) (data_get($t, 'id') ?? '');
                if ($tid !== '' && !isset($seen[$tid])) {
                    $seen[$tid] = true;
                    $allTasks[] = $t;
                }
            }
        };

        $sources = [];

        if ($spaceRestricted) {
            foreach ($spaceIdsInput as $spaceId) {
                try {
                    // For explicit space syncs, fetch every task within the space.
                    $tasksChunk = $clickUp->listSpaceTasksByAssignee((string) $spaceId, null, null, []);
                    $collectTasks($tasksChunk);
                    $sources[] = 'Space ' . $spaceId;
                } catch (\Throwable $e) {
                    \Log::error('Failed to fetch tasks from ClickUp space', [
                        'spaceId' => $spaceId,
                        'assigneeId' => $assigneeId,
                        'assigneeEmail' => $assigneeEmail,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            foreach ($teamIds as $teamId) {
                // Fetch tasks from team level (includes all spaces in the team)
                try {
                    $tasksChunk = $clickUp->listTeamTasksByAssignee((string) $teamId, $assigneeId, $assigneeEmail, []);
                    $collectTasks($tasksChunk);
                    $sources[] = 'Team ' . $teamId;
                } catch (\Throwable $e) {
                    \Log::error('Failed to fetch tasks from ClickUp team', [
                        'teamId' => $teamId,
                        'assigneeId' => $assigneeId,
                        'assigneeEmail' => $assigneeEmail,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with other teams even if one fails
                }
            }
        }
        $tasks = $allTasks;

        // If no tasks were found, check if it's due to API errors
        if (count($tasks) === 0) {
            \Log::warning('No tasks found from ClickUp', [
                'teamIds' => $teamIds,
                'assigneeId' => $assigneeId,
                'assigneeEmail' => $assigneeEmail,
                'userId' => $user->id,
                'userEmail' => $user->email,
            ]);
        }

        $created = [];
        $updated = [];
        $unchanged = 0;
        $skipped = [];

        foreach ($tasks as $t) {
            $taskId = (string) (data_get($t, 'id') ?? '');
            if ($taskId === '') {
                $skipped[] = [
                    'id' => '(unknown)',
                    'reason' => 'Missing task ID from ClickUp payload',
                ];
                continue;
            }

            if ($enforceAssigneeFilter && !$this->clickUpTaskMatchesAssignee($t, $assigneeId, $assigneeEmail)) {
                $skipped[] = [
                    'id' => $taskId,
                    'reason' => 'Task not assigned to the authenticated user',
                ];
                continue;
            }

            $clickupName = (string) (data_get($t, 'name') ?: ('Task ' . $taskId));
            $clickupUrl = (string) (data_get($t, 'url') ?: ('https://app.clickup.com/t/' . $taskId));
            $displayTitle = trim($clickupName . ' - ' . $clickupUrl);
            $priority = (string) (
                data_get($t, 'priority.label')
                ?: data_get($t, 'priority.priority')
                ?: data_get($t, 'priority')
                ?: ''
            ) ?: null;
            $dueDate = ($ms = data_get($t, 'due_date')) ? Carbon::createFromTimestampMs((int) $ms) : null;
            
            // Extract list information from ClickUp task
            $listId = (string) (data_get($t, 'list.id') ?? '');
            $listName = (string) (data_get($t, 'list.name') ?? '');

            $task = Task::firstOrNew(['clickup_task_id' => $taskId]);
            $wasCreated = !$task->exists;

            // Skip time estimate fetching during bulk sync to avoid timeouts
            // Estimates can be fetched later when viewing individual tasks
            $estimatedTime = null;
            if (data_get($t, 'time_estimate')) {
                $estimatedTime = (int) data_get($t, 'time_estimate');
            } elseif (data_get($t, 'time_estimates.total_estimate')) {
                $estimatedTime = (int) data_get($t, 'time_estimates.total_estimate');
            } elseif (data_get($t, 'time_estimates.total_estimated')) {
                $estimatedTime = (int) data_get($t, 'time_estimates.total_estimated');
            }

            $task->fill([
                'user_id' => $user->id,
                'title' => $displayTitle,
                'description' => (string) data_get($t, 'text_content'),
                'status' => (string) data_get($t, 'status.status'),
                'priority' => $priority,
                'clickup_parent_id' => (string) (data_get($t, 'parent') ?: null),
                'clickup_list_id' => $listId ?: null,
                'clickup_list_name' => $listName ?: null,
                'due_date' => $dueDate,
                'estimated_time' => $estimatedTime,
            ]);

            $dirty = $task->isDirty();
            $task->save();

            $taskPayload = [
                'id' => $taskId,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date ? $task->due_date->toIso8601String() : null,
            ];

            if ($wasCreated) {
                $created[] = $taskPayload;
            } elseif ($dirty) {
                $updated[] = $taskPayload;
            } else {
                $unchanged++;
            }
        }

        $summary = [
            'total' => count($created) + count($updated) + $unchanged,
            'created' => count($created),
            'updated' => count($updated),
            'unchanged' => $unchanged,
            'skipped' => count($skipped),
        ];

        return response()->json([
            'ok' => true,
            'summary' => $summary,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'sources' => $sources,
        ]);
    }

    /**
     * @param mixed $rawIds
     * @return array<int,string>
     */
    private function sanitizeSpaceIds(mixed $rawIds): array
    {
        if (!is_array($rawIds)) {
            return [];
        }

        $clean = [];
        foreach ($rawIds as $value) {
            if (is_string($value) || is_numeric($value)) {
                $id = trim((string) $value);
                if ($id !== '') {
                    $clean[$id] = true;
                }
            }
        }

        return array_keys($clean);
    }

    private function clickUpSpaceIncludesUser(array $space, ?string $assigneeId, ?string $assigneeEmail, ClickUpService $clickUp = null): bool
    {
        if (!$assigneeId && !$assigneeEmail) {
            return true;
        }

        $collections = [
            data_get($space, 'members', []),
            data_get($space, 'memberships', []),
            data_get($space, 'team.members', []),
            data_get($space, 'team.memberships', []),
        ];

        $hasMembershipData = false;

        $matchesMember = function (array $member) use ($space, $assigneeId, $assigneeEmail) {
            $userObj = data_get($member, 'user');

            $memberIds = [
                data_get($member, 'id'),
                data_get($member, 'user.id'),
                data_get($member, 'user_id'),
            ];
            if (is_array($userObj) && isset($userObj['id'])) {
                $memberIds[] = $userObj['id'];
            }

            foreach ($memberIds as $memberId) {
                if (!$assigneeId || !is_scalar($memberId)) {
                    continue;
                }
                $memberIdStr = trim((string) $memberId);
                if ($memberIdStr === '') {
                    continue;
                }
                if (
                    $memberIdStr === (string) $assigneeId ||
                    (is_numeric($memberIdStr) && is_numeric($assigneeId) && (int) $memberIdStr === (int) $assigneeId)
                ) {
                    \Log::debug('ClickUp space membership verified (ID match)', [
                        'spaceId' => data_get($space, 'id'),
                        'assigneeId' => $assigneeId,
                        'memberId' => $memberIdStr,
                    ]);
                    return true;
                }
            }

            $memberEmails = [
                data_get($member, 'email'),
                data_get($member, 'user.email'),
                data_get($member, 'user_email'),
            ];
            if (is_array($userObj) && isset($userObj['email'])) {
                $memberEmails[] = $userObj['email'];
            }

            foreach ($memberEmails as $memberEmail) {
                if (!$assigneeEmail || !is_scalar($memberEmail)) {
                    continue;
                }
                $memberEmailStr = trim((string) $memberEmail);
                if ($memberEmailStr === '') {
                    continue;
                }
                if (strcasecmp($memberEmailStr, trim($assigneeEmail)) === 0) {
                    \Log::debug('ClickUp space membership verified (email match in list response)', [
                        'spaceId' => data_get($space, 'id'),
                        'assigneeEmail' => $assigneeEmail,
                        'memberEmail' => $memberEmailStr,
                    ]);
                    return true;
                }
            }

            return false;
        };

        foreach ($collections as $collection) {
            if (!is_array($collection) || empty($collection)) {
                continue;
            }

            $hasMembershipData = true;

            foreach ($collection as $member) {
                if (!is_array($member)) {
                    continue;
                }

                if ($matchesMember($member)) {
                    return true;
                }
            }
        }

        if (!$hasMembershipData && $clickUp) {
            $spaceId = (string) (data_get($space, 'id') ?? '');
            if ($spaceId !== '') {
                $detailedSpace = $clickUp->getSpace($spaceId);
                if ($detailedSpace) {
                    $detailedCollections = [
                        data_get($detailedSpace, 'members', []),
                        data_get($detailedSpace, 'memberships', []),
                    ];

                    foreach ($detailedCollections as $collection) {
                        if (!is_array($collection)) {
                            continue;
                        }

                        foreach ($collection as $member) {
                            if (!is_array($member)) {
                                continue;
                            }

                            if ($matchesMember($member)) {
                                return true;
                            }
                        }
                    }

                    \Log::debug('ClickUp space - user not found in detailed space members', [
                        'spaceId' => $spaceId,
                        'assigneeId' => $assigneeId,
                        'assigneeEmail' => $assigneeEmail,
                    ]);
                } else {
                    \Log::debug('ClickUp space - detailed fetch failed or returned null', [
                        'spaceId' => $spaceId,
                    ]);
                }
            }
        }

        if ($hasMembershipData) {
            \Log::debug('ClickUp space excluded - user not found in list response membership data', [
                'spaceId' => data_get($space, 'id'),
                'assigneeId' => $assigneeId,
                'assigneeEmail' => $assigneeEmail,
            ]);
            return false;
        }

        if ($assigneeId) {
            \Log::debug('ClickUp space included - no membership data available but user has clickup_user_id', [
                'spaceId' => data_get($space, 'id'),
                'assigneeId' => $assigneeId,
            ]);
            return true;
        }

        \Log::debug('ClickUp space included - no membership data available, using email fallback', [
            'spaceId' => data_get($space, 'id'),
            'assigneeEmail' => $assigneeEmail,
        ]);
        return true;
    }


    /**
     * Get all tasks (developer only) with optional user filter.
     */
    public function index(Request $request)
    {
        $query = Task::with('user')->orderBy('updated_at', 'desc');

        // Filter by user if provided
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $tasks = $query->get(['id', 'user_id', 'title', 'status', 'priority', 'due_date', 'clickup_task_id', 'estimated_time']);

        return response()->json($tasks);
    }

    /**
     * Ensure a ClickUp task (or subtask) is assigned to the expected user/email.
     */
    private function clickUpTaskMatchesAssignee(array $task, ?string $assigneeId, ?string $assigneeEmail): bool
    {
        if (!$assigneeId && !$assigneeEmail) {
            return true;
        }

        $assignees = data_get($task, 'assignees', []);
        if (!is_array($assignees)) {
            return false;
        }

        foreach ($assignees as $assignee) {
            if (!is_array($assignee)) {
                continue;
            }

            if ($assigneeId && (string) (data_get($assignee, 'id') ?? '') === $assigneeId) {
                return true;
            }

            if ($assigneeEmail) {
                $email = (string) (data_get($assignee, 'email') ?? '');
                if ($email !== '' && strcasecmp($email, $assigneeEmail) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}


