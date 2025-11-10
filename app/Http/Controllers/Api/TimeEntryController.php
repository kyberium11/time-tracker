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
        // Get the open work entry (clocked in but not clocked out)
        $entry = TimeEntry::where('user_id', Auth::id())
            ->where('date', Carbon::today())
            ->where('entry_type', 'work')
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->first();

        // Also get current break entry if any
        $breakEntry = TimeEntry::where('user_id', Auth::id())
            ->where('date', Carbon::today())
            ->where('entry_type', 'break')
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->first();

        // Merge break info into work entry for backward compatibility
        if ($entry && $breakEntry) {
            $entry->break_start = $breakEntry->clock_in;
            $entry->break_end = null;
        }

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
                'entry_type' => 'work',
                'clock_in' => Carbon::now(),
                'total_hours' => 0,
            ]);
        } else {
            // Check if there's an open work entry (clocked in but not clocked out)
            $openEntry = TimeEntry::where('user_id', $user->id)
                ->where('date', $today)
                ->where('entry_type', 'work')
                ->whereNotNull('clock_in')
                ->whereNull('clock_out')
                ->first();
            
            if ($openEntry) {
                return response()->json(['message' => 'Already clocked in'], 400);
            }

            // Get the last entry to carry forward total_hours
            $lastEntry = TimeEntry::where('user_id', $user->id)
                ->where('date', $today)
                ->whereNotNull('clock_out')
                ->orderBy('id', 'desc')
                ->first();
            
            $accumulatedHours = $lastEntry ? $lastEntry->total_hours : 0;

            // Create a new entry for this clock-in cycle
            $entry = TimeEntry::create([
                'user_id' => $user->id,
                'date' => $today,
                'task_id' => request('task_id'),
                'entry_type' => 'work',
                'clock_in' => Carbon::now(),
                'clock_out' => null,
                'break_start' => null,
                'break_end' => null,
                'lunch_start' => null,
                'lunch_end' => null,
                'total_hours' => $accumulatedHours, // Carry forward accumulated hours
            ]);
        }

        // Log activity
        $this->logActivity('clock_in', "Clocked in at {$entry->clock_in}");

        return response()->json($entry, 201);
    }

    /**
     * Clock out.
     */
    public function clockOut(ClickUpService $clickUp)
    {
        $user = Auth::user();
        $today = Carbon::today();

        // Find the open work entry (clocked in but not clocked out)
        $entry = TimeEntry::with('task')
            ->where('user_id', $user->id)
            ->where('date', $today)
            ->where('entry_type', 'work')
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->first();

        if (!$entry) {
            return response()->json(['message' => 'You need to clock in first'], 400);
        }

        // Close current session and accumulate into total_hours
        $clockInAt = Carbon::parse((string) $entry->clock_in);
        $clockOutAt = Carbon::now();
        $entry->clock_out = $clockOutAt;
        $segmentHours = $this->calculateTotalHours($entry);
        $entry->total_hours = round(($entry->total_hours ?? 0) + $segmentHours, 2);
        $entry->save();

        // Log activity
        $this->logActivity('clock_out', "Clocked out at {$entry->clock_out}");

        // Send a reporting row to ClickUp for Time Out event
        $this->createClickUpReportRow(
            clickUp: $clickUp,
            eventName: 'Time Out',
            start: $clockInAt,
            end: $clockOutAt,
            relatedTaskId: (string) ($entry->task?->clickup_task_id ?? ''),
            userName: Auth::user()->name,
            userEmail: Auth::user()->email,
            localTaskId: (string) ($entry->task_id ?? ''),
            entryDate: Carbon::parse($entry->date)
        );

        return response()->json($entry);
    }

    /**
     * Start break.
     */
    public function startBreak()
    {
        $user = Auth::user();
        $today = Carbon::today();

        // Find the open work entry (clocked in but not clocked out)
        $workEntry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->where('entry_type', 'work')
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->first();

        if (!$workEntry) {
            return response()->json(['message' => 'You need to clock in first'], 400);
        }

        // Check if there's already an open break entry
        $openBreak = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->where('entry_type', 'break')
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->first();

        if ($openBreak) {
            return response()->json(['message' => 'You are already on break'], 400);
        }

        // Check if lunch is currently active
        if ($workEntry->lunch_start && !$workEntry->lunch_end) {
            return response()->json(['message' => 'You are currently on lunch. End lunch before starting break.'], 400);
        }

        // Create a separate break entry
        $breakEntry = TimeEntry::create([
            'user_id' => $user->id,
            'date' => $today,
            'entry_type' => 'break',
            'clock_in' => Carbon::now(),
            'total_hours' => 0,
        ]);

        // Also update work entry's break_start for backward compatibility
        $workEntry->break_start = Carbon::now();
        $workEntry->save();

        // Log activity
        $this->logActivity('break_start', "Started break at {$breakEntry->clock_in}");

        return response()->json($breakEntry);
    }

    /**
     * End break.
     */
    public function endBreak(ClickUpService $clickUp)
    {
        $user = Auth::user();
        $today = Carbon::today();

        // Find the open break entry
        $breakEntry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->where('entry_type', 'break')
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->first();

        if (!$breakEntry) {
            return response()->json(['message' => 'You need to start a break first'], 400);
        }

        $breakStartAt = Carbon::parse((string) $breakEntry->clock_in);
        $breakEndAt = Carbon::now();
        $breakEntry->clock_out = $breakEndAt;
        $breakEntry->total_hours = round($breakStartAt->diffInSeconds($breakEndAt) / 3600, 2);
        $breakEntry->save();

        // Also update work entry's break_end for backward compatibility
        $workEntry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->where('entry_type', 'work')
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->first();
        
        if ($workEntry) {
            $workEntry->break_end = $breakEndAt;
            $workEntry->save();
        }

        // Log activity
        $this->logActivity('break_end', "Ended break at {$breakEndAt}");

        // Send a reporting row to ClickUp for Break event
        $this->createClickUpReportRow(
            clickUp: $clickUp,
            eventName: 'Break',
            start: $breakStartAt,
            end: $breakEndAt,
            relatedTaskId: '',
            userName: Auth::user()->name,
            userEmail: Auth::user()->email,
            localTaskId: '',
            entryDate: Carbon::parse($breakEntry->date)
        );

        return response()->json($breakEntry);
    }

    /**
     * Start lunch.
     */
    public function startLunch()
    {
        $user = Auth::user();
        $today = Carbon::today();

        // Find the open entry (clocked in but not clocked out)
        $entry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->first();

        if (!$entry) {
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

        // Find the open entry (clocked in but not clocked out)
        $entry = TimeEntry::where('user_id', $user->id)
            ->where('date', $today)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
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
        $open = TimeEntry::with('task')
            ->where('user_id', $user->id)
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
            
            // Calculate values first
            $clickupTaskId = (string) ($open->task?->clickup_task_id ?? '');
            $start = Carbon::parse($open->clock_in);
            $end = Carbon::parse($open->clock_out);
            $durationSeconds = max(1, $start->diffInSeconds($end));
            $totalMins = round($durationSeconds / 60, 3); // minutes with second-level precision
            
            // Use Asia/Manila timezone for description to match custom fields
            $manilaTz = 'Asia/Manila';
            $startManila = $start->clone()->setTimezone($manilaTz);
            $endManila = $end->clone()->setTimezone($manilaTz);
            $durationFormatted = $this->formatDurationSeconds($durationSeconds);
            $timeInFormatted = $startManila->format('M d,Y H:i:s');
            $timeOutFormatted = $endManila->format('M d, Y H:i:s');
            $notes = "Time Tracked: {$durationFormatted} by {$user->name} ({$timeInFormatted} - {$timeOutFormatted})";
            
            $descParts = [
                'Task ID: ' . ($open->task?->clickup_task_id ?? 'n/a'),
                'Time In: ' . $timeInFormatted,
                'Time Out: ' . $timeOutFormatted,
                'Total Time (mins): ' . $totalMins,
                'User: ' . $user->name,
                'Notes: ' . $notes,
            ];
            // Prepare custom field values (also used for create-time custom_fields)
            $cfTaskId = env('CLICKUP_REPORT_CF_TASK_ID');
            $cfUser = env('CLICKUP_REPORT_CF_USER');
            $cfTimeIn = env('CLICKUP_REPORT_CF_TIME_IN');
            $cfTimeOut = env('CLICKUP_REPORT_CF_TIME_OUT');
            $cfTotalMins = env('CLICKUP_REPORT_CF_TOTAL_MINS');
            $cfNotes = env('CLICKUP_REPORT_CF_NOTES');
            
            // For Date/Time fields, use Unix timestamp in milliseconds; for text fields, use formatted string in Manila timezone
            // Format: "Nov 10,2025 10:37:00" (no space after comma)
            $timeInMs = $start->getTimestampMs();
            $timeOutMs = $end->getTimestampMs();
            $timeInText = $startManila->format('M d,Y H:i:s');
            $timeOutText = $endManila->format('M d,Y H:i:s');

            $customFields = [];
            // When creating tasks, ClickUp requires all custom field values to be strings
            if ($cfTaskId) { $customFields[] = ['id' => (string) $cfTaskId, 'value' => (string) $clickupTaskId]; }
            if ($cfUser) { $customFields[] = ['id' => (string) $cfUser, 'value' => (string) $user->name]; }
            // For Date/Time fields during creation, use text format (will be updated properly after creation)
            if ($cfTimeIn) { $customFields[] = ['id' => (string) $cfTimeIn, 'value' => (string) $timeInText]; }
            if ($cfTimeOut) { $customFields[] = ['id' => (string) $cfTimeOut, 'value' => (string) $timeOutText]; }
            if ($cfTotalMins) { $customFields[] = ['id' => (string) $cfTotalMins, 'value' => (string) $totalMins]; }
            if ($cfNotes) { $customFields[] = ['id' => (string) $cfNotes, 'value' => (string) $notes]; }

            $createPayload = [
                'name' => $taskName,
                'description' => implode("\n", $descParts),
                // Do not set status explicitly; let list default apply to avoid API errors
                'custom_fields' => $customFields,
            ];
            $created = $clickUp->createListTask((string) $reportListId, $createPayload);
            // ClickUp returns task object with 'id' field - could be numeric or custom ID
            // For custom field updates, we may need the numeric ID instead of custom ID
            $reportTaskId = null;
            if (is_array($created) && !isset($created['error'])) {
                // Try to get numeric ID first (for API updates), fallback to custom ID
                $reportTaskId = $created['id'] ?? null;
                // If it's a nested response, check for task object
                if (!$reportTaskId && isset($created['task'])) {
                    $reportTaskId = $created['task']['id'] ?? null;
                }
                // Log the response structure for debugging
                $this->logActivity('clickup_task_created_response', 'Task creation response', [
                    'response' => $created,
                    'extractedId' => $reportTaskId,
                ]);
            }

            if (!$reportTaskId) {
                $this->logActivity('clickup_report_row_error', 'Failed creating report row', [
                    'listId' => (string) $reportListId,
                    'payload' => $createPayload,
                    'response' => $created,
                ]);
                // Retry with minimal payload (name/description only); some workspaces reject custom_fields at creation
                $retryPayload = [ 'name' => $taskName, 'description' => implode("\n", $descParts) ];
                $retry = $clickUp->createListTask((string) $reportListId, $retryPayload);
                $reportTaskId = null;
                if (is_array($retry) && !isset($retry['error'])) {
                    $reportTaskId = $retry['id'] ?? null;
                    if (!$reportTaskId && isset($retry['task'])) {
                        $reportTaskId = $retry['task']['id'] ?? null;
                    }
                }
                if ($reportTaskId) {
                    $this->logActivity('clickup_report_row_retry_created', 'Created report row on retry without custom_fields', [
                        'listId' => (string) $reportListId,
                        'reportTaskId' => (string) $reportTaskId,
                    ]);
                } else {
                    $this->logActivity('clickup_report_row_retry_failed', 'Retry create failed', [
                        'listId' => (string) $reportListId,
                        'response' => $retry,
                    ]);
                }
            } else {
                $this->logActivity('clickup_report_row_created', 'Created report row', [
                    'listId' => (string) $reportListId,
                    'reportTaskId' => (string) $reportTaskId,
                ]);
            }

            // If custom field IDs are provided, set structured values (fallback if not set at creation)
            if ($reportTaskId) {
                // Add a small delay to ensure task is fully created before updating custom fields
                usleep(500000); // 0.5 second delay
                
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
                    // Try timestamp with Date/Time format first (includes value_options with time: true)
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeIn, $timeInMs, true);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        // If timestamp fails, try text format (for text fields)
                        $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeIn, $timeInText, false);
                        if (is_array($res) && ($res['error'] ?? false)) {
                            $this->logActivity('clickup_report_cf_error', 'Failed to set Time In', ['reportTaskId' => $reportTaskId, 'field' => 'TIME_IN', 'response' => $res]);
                        }
                    }
                } else { $this->logActivity('clickup_report_cf_missing', 'Missing CF id for Time In'); }

                if ($cfTimeOut) {
                    // Try timestamp with Date/Time format first (includes value_options with time: true)
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeOut, $timeOutMs, true);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        // If timestamp fails, try text format (for text fields)
                        $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeOut, $timeOutText, false);
                        if (is_array($res) && ($res['error'] ?? false)) {
                            $this->logActivity('clickup_report_cf_error', 'Failed to set Time Out', ['reportTaskId' => $reportTaskId, 'field' => 'TIME_OUT', 'response' => $res]);
                        }
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
     * Create a ClickUp reporting row (integration table) for a generic event.
     */
    private function createClickUpReportRow(
        ClickUpService $clickUp,
        string $eventName,
        Carbon $start,
        Carbon $end,
        string $relatedTaskId,
        string $userName,
        string $userEmail,
        string $localTaskId,
        Carbon $entryDate
    ): void {
        $reportListId = env('CLICKUP_REPORT_LIST_ID');
        if (!$reportListId) { return; }

        // Use Asia/Manila timezone for description to match custom fields
        $manilaTz = 'Asia/Manila';
        $startManila = $start->clone()->setTimezone($manilaTz);
        $endManila = $end->clone()->setTimezone($manilaTz);
        $durationFormatted = $this->formatDurationSeconds($durationSeconds);
        $timeInFormatted = $startManila->format('M d,Y H:i:s');
        $timeOutFormatted = $endManila->format('M d, Y H:i:s');
        
        $descParts = [
            'Task ID: ' . ($relatedTaskId ?: 'n/a'),
            'Time In: ' . $timeInFormatted,
            'Time Out: ' . $timeOutFormatted,
            'Total Time (mins): ' . $totalMins,
            'User: ' . $userName,
            'Notes: ' . $notes,
        ];

        // Prepare custom field values
        $cfTaskId = env('CLICKUP_REPORT_CF_TASK_ID');
        $cfUser = env('CLICKUP_REPORT_CF_USER');
        $cfTimeIn = env('CLICKUP_REPORT_CF_TIME_IN');
        $cfTimeOut = env('CLICKUP_REPORT_CF_TIME_OUT');
        $cfTotalMins = env('CLICKUP_REPORT_CF_TOTAL_MINS');
        $cfNotes = env('CLICKUP_REPORT_CF_NOTES');

        $clickupTaskId = (string) $relatedTaskId;
        $durationSeconds = max(1, $start->diffInSeconds($end));
        $totalMins = round($durationSeconds / 60, 3);
        
        // Use Asia/Manila timezone for Time In/Time Out and Notes
        $manilaTz = 'Asia/Manila';
        $startManila = $start->clone()->setTimezone($manilaTz);
        $endManila = $end->clone()->setTimezone($manilaTz);
        
        // Format notes as "Time Tracked: +0h0m0s by User Name (Nov 10,2025 10:37:00 - Nov 10, 2025 10:37:00)"
        $durationFormatted = $this->formatDurationSeconds($durationSeconds);
        $timeInFormatted = $startManila->format('M d,Y H:i:s');
        $timeOutFormatted = $endManila->format('M d, Y H:i:s');
        $notes = "Time Tracked: {$durationFormatted} by {$userName} ({$timeInFormatted} - {$timeOutFormatted})";
        
        // For Date/Time fields, use Unix timestamp in milliseconds; for text fields, use formatted string in Manila timezone
        // Format: "Nov 10,2025 10:37:00" (no space after comma)
        $timeInMs = $start->getTimestampMs();
        $timeOutMs = $end->getTimestampMs();
        $timeInText = $startManila->format('M d,Y H:i:s');
        $timeOutText = $endManila->format('M d,Y H:i:s');

        $customFields = [];
        // When creating tasks, ClickUp requires all custom field values to be strings
        if ($cfTaskId) { $customFields[] = ['id' => (string) $cfTaskId, 'value' => (string) $clickupTaskId]; }
        if ($cfUser) { $customFields[] = ['id' => (string) $cfUser, 'value' => (string) $userName]; }
        // For Date/Time fields during creation, use text format (will be updated properly after creation)
        if ($cfTimeIn) { $customFields[] = ['id' => (string) $cfTimeIn, 'value' => (string) $timeInText]; }
        if ($cfTimeOut) { $customFields[] = ['id' => (string) $cfTimeOut, 'value' => (string) $timeOutText]; }
        if ($cfTotalMins) { $customFields[] = ['id' => (string) $cfTotalMins, 'value' => (string) $totalMins]; }
        if ($cfNotes) { $customFields[] = ['id' => (string) $cfNotes, 'value' => (string) $notes]; }

        // Use event name as the task name; append date for readability
        $taskName = $eventName;

        $createPayload = [
            'name' => $taskName,
            'description' => implode("\n", $descParts),
            'custom_fields' => $customFields,
        ];

        $created = $clickUp->createListTask((string) $reportListId, $createPayload);
        // ClickUp returns task object with 'id' field - could be numeric or custom ID
        $reportTaskId = null;
        if (is_array($created) && !isset($created['error'])) {
            $reportTaskId = $created['id'] ?? null;
            // If it's a nested response, check for task object
            if (!$reportTaskId && isset($created['task'])) {
                $reportTaskId = $created['task']['id'] ?? null;
            }
        }
        if (!$reportTaskId) {
            $this->logActivity('clickup_report_row_error', 'Failed creating report row', [
                'listId' => (string) $reportListId,
                'payload' => $createPayload,
                'response' => $created,
                'eventName' => $eventName,
            ]);
            // Retry with minimal payload (name/description only); some workspaces reject custom_fields at creation
            $retryPayload = [ 'name' => $taskName, 'description' => implode("\n", $descParts) ];
            $retry = $clickUp->createListTask((string) $reportListId, $retryPayload);
            $reportTaskId = is_array($retry) ? ($retry['id'] ?? null) : null;
            if ($reportTaskId) {
                $this->logActivity('clickup_report_row_retry_created', 'Created report row on retry without custom_fields', [
                    'listId' => (string) $reportListId,
                    'reportTaskId' => (string) $reportTaskId,
                    'eventName' => $eventName,
                ]);
            } else {
                $this->logActivity('clickup_report_row_retry_failed', 'Retry create failed', [
                    'listId' => (string) $reportListId,
                    'response' => $retry,
                    'eventName' => $eventName,
                ]);
            }
        } else {
            $this->logActivity('clickup_report_row_created', 'Created report row', [
                'listId' => (string) $reportListId,
                'reportTaskId' => (string) $reportTaskId,
                'eventName' => $eventName,
            ]);
        }

        if ($reportTaskId) {
            // Add a small delay to ensure task is fully created before updating custom fields
            usleep(500000); // 0.5 second delay
            
            if ($cfTaskId) {
                $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTaskId, $clickupTaskId);
                if (is_array($res) && ($res['error'] ?? false)) {
                    $this->logActivity('clickup_report_cf_error', 'Failed to set Task ID', ['reportTaskId' => $reportTaskId, 'field' => 'TASK_ID', 'response' => $res, 'eventName' => $eventName]);
                }
            }
            if ($cfUser) {
                $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfUser, (string) $userName);
                if (is_array($res) && ($res['error'] ?? false)) {
                    $this->logActivity('clickup_report_cf_error', 'Failed to set User', ['reportTaskId' => $reportTaskId, 'field' => 'USER', 'response' => $res, 'eventName' => $eventName]);
                }
            }
            if ($cfTimeIn) {
                // Try timestamp with Date/Time format first (includes value_options with time: true)
                $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeIn, $timeInMs, true);
                if (is_array($res) && ($res['error'] ?? false)) {
                    // If timestamp fails, try text format (for text fields)
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeIn, $timeInText, false);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        $this->logActivity('clickup_report_cf_error', 'Failed to set Time In', ['reportTaskId' => $reportTaskId, 'field' => 'TIME_IN', 'response' => $res, 'eventName' => $eventName]);
                    }
                }
            }
            if ($cfTimeOut) {
                // Try timestamp with Date/Time format first (includes value_options with time: true)
                $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeOut, $timeOutMs, true);
                if (is_array($res) && ($res['error'] ?? false)) {
                    // If timestamp fails, try text format (for text fields)
                    $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTimeOut, $timeOutText, false);
                    if (is_array($res) && ($res['error'] ?? false)) {
                        $this->logActivity('clickup_report_cf_error', 'Failed to set Time Out', ['reportTaskId' => $reportTaskId, 'field' => 'TIME_OUT', 'response' => $res, 'eventName' => $eventName]);
                    }
                }
            }
            if ($cfTotalMins) {
                $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfTotalMins, $totalMins);
                if (is_array($res) && ($res['error'] ?? false)) {
                    $this->logActivity('clickup_report_cf_error', 'Failed to set Total Time (mins)', ['reportTaskId' => $reportTaskId, 'field' => 'TOTAL_MINS', 'response' => $res, 'eventName' => $eventName]);
                }
            }
            if ($cfNotes) {
                $res = $clickUp->updateTaskCustomField((string) $reportTaskId, (string) $cfNotes, $notes);
                if (is_array($res) && ($res['error'] ?? false)) {
                    $this->logActivity('clickup_report_cf_error', 'Failed to set Notes', ['reportTaskId' => $reportTaskId, 'field' => 'NOTES', 'response' => $res, 'eventName' => $eventName]);
                }
            }
        }
    }

    /**
     * Format a duration in seconds as +0h0m0s (e.g., +2h30m15s, no zero-padding).
     */
    private function formatDurationSeconds(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('+%dh%dm%ds', $hours, $minutes, $secs);
    }
}
