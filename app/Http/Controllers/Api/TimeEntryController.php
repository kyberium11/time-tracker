<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\Task;
use App\Models\UserActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Services\ClickUpService;

class TimeEntryController extends Controller
{
    /**
     * Get current time entry for today.
     */
    public function getCurrentEntry()
    {
        $entry = TimeEntry::where('user_id', Auth::id())
            ->where('date', Carbon::today())
            ->first();

        return response()->json($entry ?: null);
    }

    /**
     * Clock in.
     */
    public function clockIn()
    {
        $user = Auth::user();
        $today = Carbon::today();

        // Get any existing entry for today (do not restrict multiple Time In presses)
        $existingEntry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        // Create or update entry
        $entry = TimeEntry::updateOrCreate(
            [
                'user_id' => $user->id,
                'date' => $today,
            ],
            [
                'clock_in' => Carbon::now(),
                // Allow optional task_id from request; keep existing if not provided
                'task_id' => request('task_id') ?? ($existingEntry->task_id ?? null),
                // If re-starting after a Time Out, clear end fields to reopen today's entry
                'clock_out' => null,
                'break_start' => null,
                'break_end' => null,
                'lunch_start' => null,
                'lunch_end' => null,
                'total_hours' => 0,
            ]
        );

        // Log activity
        $this->logActivity('clock_in', "Clocked in at {$entry->clock_in}");

        return response()->json($entry, 201);
    }

    /**
     * Clock out.
     */
    public function clockOut()
    {
        $user = Auth::user();
        $today = Carbon::today();

        $entry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        if (!$entry || !$entry->clock_in) {
            return response()->json(['message' => 'You need to clock in first'], 400);
        }

        // Allow multiple time-outs in a day; last one wins

        $entry->clock_out = Carbon::now();
        $entry->total_hours = $this->calculateTotalHours($entry);
        $entry->save();

        // Log activity
        $this->logActivity('clock_out', "Clocked out at {$entry->clock_out}");

        return response()->json($entry);
    }

    /**
     * Start break.
     */
    public function startBreak()
    {
        $user = Auth::user();
        $today = Carbon::today();

        $entry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        if (!$entry || !$entry->clock_in) {
            return response()->json(['message' => 'You need to clock in first'], 400);
        }

        if ($entry->break_start && !$entry->break_end) {
            return response()->json(['message' => 'You are already on break'], 400);
        }

        // Check if lunch is currently active
        if ($entry->lunch_start && !$entry->lunch_end) {
            return response()->json(['message' => 'You are currently on lunch. End lunch before starting break.'], 400);
        }

        $entry->break_start = Carbon::now();
        $entry->save();

        // Log activity
        $this->logActivity('break_start', "Started break at {$entry->break_start}");

        return response()->json($entry);
    }

    /**
     * End break.
     */
    public function endBreak()
    {
        $user = Auth::user();
        $today = Carbon::today();

        $entry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        if (!$entry || !$entry->break_start) {
            return response()->json(['message' => 'You need to start a break first'], 400);
        }

        if ($entry->break_end) {
            return response()->json(['message' => 'Break already ended'], 400);
        }

        $entry->break_end = Carbon::now();
        $entry->total_hours = $this->calculateTotalHours($entry);
        $entry->save();

        // Log activity
        $this->logActivity('break_end', "Ended break at {$entry->break_end}");

        return response()->json($entry);
    }

    /**
     * Start lunch.
     */
    public function startLunch()
    {
        $user = Auth::user();
        $today = Carbon::today();

        $entry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        if (!$entry || !$entry->clock_in) {
            return response()->json(['message' => 'You need to clock in first'], 400);
        }

        if ($entry->lunch_start && !$entry->lunch_end) {
            return response()->json(['message' => 'You are already on lunch'], 400);
        }

        // Check if break is currently active
        if ($entry->break_start && !$entry->break_end) {
            return response()->json(['message' => 'You are currently on break. End break before starting lunch.'], 400);
        }

        $entry->lunch_start = Carbon::now();
        $entry->save();

        // Log activity
        $this->logActivity('lunch_start', "Started lunch at {$entry->lunch_start}");

        return response()->json($entry);
    }

    /**
     * End lunch.
     */
    public function endLunch()
    {
        $user = Auth::user();
        $today = Carbon::today();

        $entry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        if (!$entry || !$entry->lunch_start) {
            return response()->json(['message' => 'You need to start lunch first'], 400);
        }

        if ($entry->lunch_end) {
            return response()->json(['message' => 'Lunch already ended'], 400);
        }

        $entry->lunch_end = Carbon::now();
        $entry->total_hours = $this->calculateTotalHours($entry);
        $entry->save();

        // Log activity
        $this->logActivity('lunch_end', "Ended lunch at {$entry->lunch_end}");

        return response()->json($entry);
    }

    /**
     * Get my time entries.
     */
    public function myEntries()
    {
        $entries = TimeEntry::where('user_id', Auth::id())
            ->orderBy('date', 'desc')
            ->paginate(30);

        return response()->json($entries);
    }

    /**
     * Start a task timer (independent of Work/Break timers).
     */
    public function startTask()
    {
        $user = Auth::user();
        $taskId = request('task_id');
        if (!$taskId || !Task::whereKey($taskId)->exists()) {
            return response()->json(['message' => 'Invalid task'], 422);
        }

        // Close any open task timer for today first
        TimeEntry::where('user_id', $user->id)
            ->whereDate('created_at', Carbon::today())
            ->whereNotNull('task_id')
            ->whereNull('clock_out')
            ->update(['clock_out' => Carbon::now()]);

        $entry = TimeEntry::create([
            'user_id' => $user->id,
            'date' => Carbon::today(),
            'task_id' => $taskId,
            'clock_in' => Carbon::now(),
        ]);

        $this->logActivity('task_start', "Started task #{$taskId} at {$entry->clock_in}");
        return response()->json($entry, 201);
    }

    /**
     * Stop the current task timer.
     */
    public function stopTask(ClickUpService $clickUp)
    {
        $user = Auth::user();
        $open = TimeEntry::where('user_id', $user->id)
            ->whereDate('created_at', Carbon::today())
            ->whereNotNull('task_id')
            ->whereNull('clock_out')
            ->latest('id')
            ->first();

        if (!$open) {
            return response()->json(['message' => 'No running task'], 400);
        }

        $open->clock_out = Carbon::now();
        $open->total_hours = $this->calculateTotalHours($open);
        $open->save();

        $this->logActivity('task_stop', "Stopped task #{$open->task_id} at {$open->clock_out}");

        // Push to ClickUp time tracking (v2 Create a time entry)
        $teamId = env('CLICKUP_TEAM_ID');
        if ($teamId && $open->task && $open->task->clickup_task_id) {
            $durationMs = Carbon::parse($open->clock_out)->diffInMilliseconds(Carbon::parse($open->clock_in));
            $payload = [
                'tid' => (string) $open->task->clickup_task_id, // task id in ClickUp
                'start' => Carbon::parse($open->clock_in)->getTimestampMs(),
                'duration' => $durationMs,
                'billable' => true,
                'assignee' => (string) (Auth::user()->clickup_user_id ?? ''),
                'description' => 'Synced from Time Tracker',
            ];
            $clickUp->createTimeEntry($teamId, $payload);
        }

        return response()->json($open);
    }

    /**
     * Today task timers for the current user.
     */
    public function todayTaskEntries()
    {
        $rows = TimeEntry::with('task')
            ->where('user_id', Auth::id())
            ->whereDate('date', Carbon::today())
            ->whereNotNull('task_id')
            ->orderBy('id')
            ->get();

        return response()->json($rows);
    }

    /**
     * Calculate total hours worked.
     */
    private function calculateTotalHours(TimeEntry $entry): float
    {
        if (!$entry->clock_in || !$entry->clock_out) {
            return 0;
        }

        $totalMinutes = 0;
        $clockIn = Carbon::parse($entry->clock_in);
        $clockOut = Carbon::parse($entry->clock_out);

        // Base working time
        $totalMinutes = $clockOut->diffInMinutes($clockIn);

        // Subtract break time
        if ($entry->break_start && $entry->break_end) {
            $breakStart = Carbon::parse($entry->break_start);
            $breakEnd = Carbon::parse($entry->break_end);
            $totalMinutes -= $breakStart->diffInMinutes($breakEnd);
        }

        // Subtract lunch time
        if ($entry->lunch_start && $entry->lunch_end) {
            $lunchStart = Carbon::parse($entry->lunch_start);
            $lunchEnd = Carbon::parse($entry->lunch_end);
            $totalMinutes -= $lunchStart->diffInMinutes($lunchEnd);
        }

        return round($totalMinutes / 60, 2);
    }

    /**
     * Log user activity.
     */
    private function logActivity(string $action, string $description, array $metadata = []): void
    {
        UserActivityLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
