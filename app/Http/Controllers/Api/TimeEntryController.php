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

        // Get any existing entry for today
        $entry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        // If no entry for today, create a fresh one
        if (!$entry) {
            $entry = TimeEntry::create([
                'user_id' => $user->id,
                'date' => $today,
                'task_id' => request('task_id'),
                'clock_in' => Carbon::now(),
                'total_hours' => 0,
            ]);
        } else {
            // Prevent overwriting an active session
            if ($entry->clock_in && !$entry->clock_out) {
                return response()->json(['message' => 'Already clocked in'], 400);
            }

            // Re-open a new work session for today without resetting accumulated total_hours
            $entry->clock_in = Carbon::now();
            $entry->clock_out = null;
            $entry->break_start = null;
            $entry->break_end = null;
            $entry->lunch_start = null;
            $entry->lunch_end = null;
            if (request()->has('task_id')) {
                $entry->task_id = request('task_id');
            }
            $entry->save();
        }

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

        // Close current session and accumulate into total_hours
        $entry->clock_out = Carbon::now();
        $segmentHours = $this->calculateTotalHours($entry);
        $entry->total_hours = round(($entry->total_hours ?? 0) + $segmentHours, 2);

        // Reset session fields to allow new sessions later today
        $entry->clock_in = null;
        $entry->clock_out = null;
        $entry->break_start = null;
        $entry->break_end = null;
        $entry->lunch_start = null;
        $entry->lunch_end = null;
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
        // Do not modify total_hours here; accumulation occurs on clock out
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
        // Do not modify total_hours here; accumulation occurs on clock out
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

        // Optionally push native ClickUp time entries if explicitly enabled
        $pushNative = filter_var(env('CLICKUP_PUSH_TIME_ENTRIES', false), FILTER_VALIDATE_BOOL);
        $teamId = env('CLICKUP_TEAM_ID');
        if ($pushNative && $teamId && $open->task && $open->task->clickup_task_id) {
            $startMs = Carbon::parse($open->clock_in)->getTimestampMs();
            $endMs = Carbon::parse($open->clock_out)->getTimestampMs();
            $durationMs = max(1000, $endMs - $startMs);
            $payload = [
                'tid' => (string) $open->task->clickup_task_id,
                'task_id' => (string) $open->task->clickup_task_id,
                'start' => $startMs,
                'end' => $endMs,
                'duration' => $durationMs,
                'billable' => true,
                'description' => 'Synced from Time Tracker',
            ];
            $result = $clickUp->createTimeEntry($teamId, $payload);
            if (isset($result['error']) && $result['error']) {
                $this->logActivity('clickup_time_entry_error', 'ClickUp time entry failed', [
                    'status' => $result['status'] ?? null,
                    'body' => $result['body'] ?? null,
                    'payload' => $payload,
                ]);
            } else {
                $this->logActivity('clickup_time_entry_synced', 'ClickUp time entry created', [
                    'payload' => $payload,
                    'response' => $result,
                ]);
            }
        }

        // Update ClickUp task custom fields and add a comment (without using native time entries)
        if ($teamId && $open->task && $open->task->clickup_task_id) {
            $clickupTaskId = (string) $open->task->clickup_task_id;

            // Aggregate hours from local DB for this task
            $totalHours = TimeEntry::where('task_id', $open->task_id)->sum('total_hours');
            $todayHours = TimeEntry::where('task_id', $open->task_id)
                ->whereDate('date', Carbon::today())
                ->sum('total_hours');
            $weekStart = Carbon::now()->startOfWeek();
            $weekEnd = Carbon::now()->endOfWeek();
            $weekHours = TimeEntry::where('task_id', $open->task_id)
                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->sum('total_hours');

            $cfTotal = env('CLICKUP_CF_TOTAL_HOURS_ID');
            $cfToday = env('CLICKUP_CF_TODAY_HOURS_ID');
            $cfWeek = env('CLICKUP_CF_WEEK_HOURS_ID');

            if ($cfTotal) { $clickUp->updateTaskCustomField($clickupTaskId, (string) $cfTotal, round($totalHours, 2)); }
            if ($cfToday) { $clickUp->updateTaskCustomField($clickupTaskId, (string) $cfToday, round($todayHours, 2)); }
            if ($cfWeek) { $clickUp->updateTaskCustomField($clickupTaskId, (string) $cfWeek, round($weekHours, 2)); }

            // Add a structured comment with second-level precision
            $start = Carbon::parse($open->clock_in);
            $end = Carbon::parse($open->clock_out);
            $displayTz = env('CLICKUP_DISPLAY_TZ', config('app.timezone'));
            $durationSeconds = max(1, $start->diffInSeconds($end));
            $comment = 'Time Tracker: +' . $this->formatDurationSeconds($durationSeconds) . ' by ' . $user->name . ' (' . $start->clone()->setTimezone($displayTz)->format('Y-m-d H:i:s') . '–' . $end->clone()->setTimezone($displayTz)->format('Y-m-d H:i:s') . ' ' . $displayTz . ')';
            $clickUp->addTaskComment($clickupTaskId, $comment);
        }

        // Create a reporting row in the dedicated ClickUp List (integration table)
        $reportListId = env('CLICKUP_REPORT_LIST_ID');
        if ($reportListId) {
            $clickupTaskIdForUrl = (string) ($open->task?->clickup_task_id ?? '');
            $taskUrl = $clickupTaskIdForUrl ? ('https://app.clickup.com/t/' . $clickupTaskIdForUrl) : (Carbon::parse($open->date)->toDateString() . ' | Task #' . $open->task_id);
            $taskName = $taskUrl;
            $displayTz = env('CLICKUP_DISPLAY_TZ', config('app.timezone'));
            $descParts = [
                'User: ' . $user->name,
                'Email: ' . $user->email,
                'Local Task ID: ' . $open->task_id,
                'ClickUp Task ID: ' . ($open->task?->clickup_task_id ?? 'n/a'),
                'Start: ' . Carbon::parse($open->clock_in)->setTimezone($displayTz)->format('Y-m-d H:i:s') . ' ' . $displayTz,
                'End: ' . Carbon::parse($open->clock_out)->setTimezone($displayTz)->format('Y-m-d H:i:s') . ' ' . $displayTz,
                'Hours: ' . round(Carbon::parse($open->clock_in)->diffInSeconds(Carbon::parse($open->clock_out)) / 3600, 2),
            ];
            // Prepare custom field values (also used for create-time custom_fields)
            $cfTaskId = env('CLICKUP_REPORT_CF_TASK_ID');
            $cfUser = env('CLICKUP_REPORT_CF_USER');
            $cfTimeIn = env('CLICKUP_REPORT_CF_TIME_IN');
            $cfTimeOut = env('CLICKUP_REPORT_CF_TIME_OUT');
            $cfTotalMins = env('CLICKUP_REPORT_CF_TOTAL_MINS');
            $cfNotes = env('CLICKUP_REPORT_CF_NOTES');

            $clickupTaskId = (string) ($open->task?->clickup_task_id ?? '');
            $start = Carbon::parse($open->clock_in);
            $end = Carbon::parse($open->clock_out);
            $timeInMs = $start->getTimestampMs();
            $timeOutMs = $end->getTimestampMs();
            $durationSeconds = max(1, $start->diffInSeconds($end));
            $totalMins = round($durationSeconds / 60, 3); // minutes with second-level precision
            $notes = 'Time Tracker: +' . round($durationSeconds / 3600, 2) . 'h by ' . $user->name . ' (' . $start->clone()->setTimezone($displayTz)->format('Y-m-d H:i:s') . ' – ' . $end->clone()->setTimezone($displayTz)->format('Y-m-d H:i:s') . ' ' . $displayTz . ')';

            $customFields = [];
            if ($cfTaskId) { $customFields[] = ['id' => (string) $cfTaskId, 'value' => $clickupTaskId]; }
            if ($cfUser) { $customFields[] = ['id' => (string) $cfUser, 'value' => (string) $user->name]; }
            if ($cfTimeIn) { $customFields[] = ['id' => (string) $cfTimeIn, 'value' => $timeInMs]; }
            if ($cfTimeOut) { $customFields[] = ['id' => (string) $cfTimeOut, 'value' => $timeOutMs]; }
            if ($cfTotalMins) { $customFields[] = ['id' => (string) $cfTotalMins, 'value' => $totalMins]; }
            if ($cfNotes) { $customFields[] = ['id' => (string) $cfNotes, 'value' => $notes]; }

            $createPayload = [
                'name' => $taskName,
                'description' => implode("\n", $descParts),
                // Do not set status explicitly; let list default apply to avoid API errors
                'custom_fields' => $customFields,
            ];
            $created = $clickUp->createListTask((string) $reportListId, $createPayload);
            $reportTaskId = is_array($created) ? ($created['id'] ?? null) : null;

            if (!$reportTaskId) {
                $this->logActivity('clickup_report_row_error', 'Failed creating report row', [
                    'listId' => (string) $reportListId,
                    'payload' => $createPayload,
                    'response' => $created,
                ]);
            } else {
                $this->logActivity('clickup_report_row_created', 'Created report row', [
                    'listId' => (string) $reportListId,
                    'reportTaskId' => (string) $reportTaskId,
                ]);
            }

            // If custom field IDs are provided, set structured values (fallback if not set at creation)
            if ($reportTaskId) {
                if ($cfTaskId) {
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTaskId, $clickupTaskId);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        $this->logActivity('clickup_report_cf_error', 'Failed to set Task ID', ['reportTaskId' => $reportTaskId, 'field' => 'TASK_ID', 'response' => $res]);
                    }
                } else { $this->logActivity('clickup_report_cf_missing', 'Missing CF id for Task ID'); }

                if ($cfUser) {
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfUser, (string) $user->name);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        $this->logActivity('clickup_report_cf_error', 'Failed to set User', ['reportTaskId' => $reportTaskId, 'field' => 'USER', 'response' => $res]);
                    }
                } else { $this->logActivity('clickup_report_cf_missing', 'Missing CF id for User'); }

                if ($cfTimeIn) {
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeIn, $timeInMs);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        $this->logActivity('clickup_report_cf_error', 'Failed to set Time In', ['reportTaskId' => $reportTaskId, 'field' => 'TIME_IN', 'response' => $res]);
                    }
                } else { $this->logActivity('clickup_report_cf_missing', 'Missing CF id for Time In'); }

                if ($cfTimeOut) {
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeOut, $timeOutMs);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        $this->logActivity('clickup_report_cf_error', 'Failed to set Time Out', ['reportTaskId' => $reportTaskId, 'field' => 'TIME_OUT', 'response' => $res]);
                    }
                } else { $this->logActivity('clickup_report_cf_missing', 'Missing CF id for Time Out'); }

                if ($cfTotalMins) {
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTotalMins, $totalMins);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        $this->logActivity('clickup_report_cf_error', 'Failed to set Total Time (mins)', ['reportTaskId' => $reportTaskId, 'field' => 'TOTAL_MINS', 'response' => $res]);
                    }
                } else { $this->logActivity('clickup_report_cf_missing', 'Missing CF id for Total Time (mins)'); }

                if ($cfNotes) {
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfNotes, $notes);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        $this->logActivity('clickup_report_cf_error', 'Failed to set Notes', ['reportTaskId' => $reportTaskId, 'field' => 'NOTES', 'response' => $res]);
                    }
                } else { $this->logActivity('clickup_report_cf_missing', 'Missing CF id for Notes'); }
            }
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

    /**
     * Format a duration in seconds as Hh Mm Ss, omitting zero units except seconds.
     */
    private function formatDurationSeconds(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($hours > 0) { $parts[] = $hours . 'h'; }
        if ($minutes > 0 || $hours > 0) { $parts[] = $minutes . 'm'; }
        $parts[] = $secs . 's';
        return implode(' ', $parts);
    }
}
