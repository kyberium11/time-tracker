<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Team;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use App\Services\ClickUpService;
use Carbon\Carbon;

class UserManagementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::with('team')->latest()->paginate(15);
        return response()->json($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => 'required|in:admin,manager,employee',
            'team_id' => 'nullable|exists:teams,id',
            'shift_start' => 'nullable|date_format:H:i',
            'shift_end' => 'nullable|date_format:H:i',
            'clickup_user_id' => 'nullable|string|max:255|unique:users,clickup_user_id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'team_id' => $validated['team_id'] ?? null,
            'shift_start' => $validated['shift_start'] ?? null,
            'shift_end' => $validated['shift_end'] ?? null,
            'clickup_user_id' => $validated['clickup_user_id'] ?? null,
        ]);

        return response()->json($user, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::with(['timeEntries', 'team'])->findOrFail($id);
        return response()->json($user);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $id,
            'password' => ['sometimes', 'nullable', 'confirmed', Password::defaults()],
            'role' => 'sometimes|required|in:admin,manager,employee',
            'team_id' => 'nullable|exists:teams,id',
            'shift_start' => 'nullable|date_format:H:i',
            'shift_end' => 'nullable|date_format:H:i',
            'clickup_user_id' => 'nullable|string|max:255|unique:users,clickup_user_id,' . $id,
        ]);

        if ($request->has('name')) {
            $user->name = $validated['name'];
        }
        if ($request->has('email')) {
            $user->email = $validated['email'];
        }
        if ($request->has('password') && $validated['password']) {
            $user->password = Hash::make($validated['password']);
        }
        if ($request->has('role')) {
            $user->role = $validated['role'];
        }
        if ($request->has('team_id')) {
            $user->team_id = $validated['team_id'];
        }
        if ($request->has('shift_start')) {
            $user->shift_start = $validated['shift_start'];
        }
        if ($request->has('shift_end')) {
            $user->shift_end = $validated['shift_end'];
        }
        if ($request->has('clickup_user_id')) {
            $user->clickup_user_id = $validated['clickup_user_id'];
        }

        $user->save();

        return response()->json($user);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted successfully'], 200);
    }

    /**
     * Manually sync a user's tasks from ClickUp based on their ClickUp user id or email.
     */
    public function syncClickUpTasks(string $id, ClickUpService $clickUp)
    {
        $user = User::findOrFail($id);

        $teamId = (string) (env('CLICKUP_TEAM_ID') ?? '');
        if ($teamId === '') {
            return response()->json(['error' => 'CLICKUP_TEAM_ID is not configured'], 400);
        }

        // Prefer numeric clickup_user_id; fallback to email if missing
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
                ]
            );
            $upserted++;
        }

        return response()->json([
            'ok' => true,
            'count' => $upserted,
        ]);
    }
}
