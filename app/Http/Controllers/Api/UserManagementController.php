<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewUserCredentialsMail;
use App\Models\User;
use App\Models\Team;
use App\Models\Task;
use App\Models\UserShiftSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
        // Show all users, but display developers as "employee" to admins
        $users = User::with(['team', 'shiftSchedules'])->latest()->paginate(15);
        
        // Map developer role to employee for admin display
        $users->getCollection()->transform(function ($user) {
            return $this->transformUser($user);
        });
        
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
            'shift_schedule' => 'nullable|array',
            'shift_schedule.*.day_of_week' => 'required|integer|between:0,6',
            'shift_schedule.*.start_time' => 'required|date_format:H:i',
            'shift_schedule.*.end_time' => 'required|date_format:H:i',
        ]);

        $plainPassword = $validated['password'];

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($plainPassword),
            'role' => $validated['role'],
            'team_id' => $validated['team_id'] ?? null,
            'shift_start' => $validated['shift_start'] ?? null,
            'shift_end' => $validated['shift_end'] ?? null,
            'clickup_user_id' => $validated['clickup_user_id'] ?? null,
        ]);

        if (!empty($validated['shift_schedule'])) {
            $this->syncShiftSchedule($user, $validated['shift_schedule']);
        }

        $this->sendWelcomeEmail($user, $plainPassword);

        $user->load(['team', 'shiftSchedules']);

        return response()->json($this->transformUser($user), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // Show user, but display developer role as "employee" to admins
        $user = User::with(['timeEntries', 'team', 'shiftSchedules'])->findOrFail($id);
        
        // Map developer role to employee for admin display
        return response()->json($this->transformUser($user));
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
            'shift_schedule' => 'nullable|array',
            'shift_schedule.*.day_of_week' => 'required|integer|between:0,6',
            'shift_schedule.*.start_time' => 'required|date_format:H:i',
            'shift_schedule.*.end_time' => 'required|date_format:H:i',
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
            // Prevent admin from changing role to developer
            if ($validated['role'] === 'developer') {
                return response()->json(['error' => 'Invalid role'], 400);
            }
            // Prevent admin from changing developer users' roles
            if ($user->role === 'developer') {
                return response()->json(['error' => 'Cannot modify developer user role'], 400);
            }
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

        if ($request->has('shift_schedule')) {
            $this->syncShiftSchedule($user, $validated['shift_schedule'] ?? []);
        }

        $user->save();

        $user->loadMissing(['team', 'shiftSchedules']);

        return response()->json($this->transformUser($user));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        
        // Prevent admin from deleting developer users
        if ($user->role === 'developer') {
            return response()->json(['error' => 'Cannot delete developer user'], 400);
        }
        
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

            if (!$this->clickUpTaskMatchesAssignee($t, $assigneeId, $assigneeEmail)) {
                continue;
            }

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
                    'estimated_time' => $clickUp->resolveTaskEstimate($t),
                ]
            );
            $upserted++;
        }

        return response()->json([
            'ok' => true,
            'count' => $upserted,
        ]);
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

    /**
     * Send welcome email with credentials.
     */
    protected function sendWelcomeEmail(User $user, string $plainPassword): void
    {
        try {
            Mail::to($user->email)->send(
                new NewUserCredentialsMail($user->name, $user->email, $plainPassword)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send new user credentials email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync the shift schedule records for the user.
     *
     * @param  array<int,array<string,mixed>>  $schedule
     */
    protected function syncShiftSchedule(User $user, array $schedule): void
    {
        $normalized = collect($schedule)
            ->filter(function ($entry) {
                return isset($entry['day_of_week'], $entry['start_time'], $entry['end_time']);
            })
            ->map(function ($entry) {
                return [
                    'day_of_week' => (int) $entry['day_of_week'],
                    'start_time' => $entry['start_time'],
                    'end_time' => $entry['end_time'],
                ];
            })
            ->unique('day_of_week')
            ->values();

        $user->shiftSchedules()->delete();

        if ($normalized->isEmpty()) {
            return;
        }

        $user->shiftSchedules()->createMany($normalized->all());
    }

    /**
     * Prepare user payload with shift schedule details.
     */
    protected function transformUser(User $user)
    {
        if ($user->role === 'developer') {
            $user->role = 'employee';
        }

        $user->setAttribute('shift_schedule', $user->shiftSchedules->map(function (UserShiftSchedule $schedule) {
            return [
                'day_of_week' => $schedule->day_of_week,
                'start_time' => substr($schedule->start_time, 0, 5),
                'end_time' => substr($schedule->end_time, 0, 5),
            ];
        })->values());

        $user->unsetRelation('shiftSchedules');

        return $user;
    }
}
