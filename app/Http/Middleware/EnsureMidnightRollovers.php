<?php

namespace App\Http\Middleware;

use App\Models\TimeEntry;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureMidnightRollovers
{
    /**
     * Close any open time entries from previous days at their day's end.
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $userId = Auth::id();
            $today = Carbon::today();

            // Close open day work entries (no clock_out) from previous dates
            $openDays = TimeEntry::where('user_id', $userId)
                ->whereDate('date', '<', $today)
                ->whereNull('clock_out')
                ->get();

            foreach ($openDays as $entry) {
                // Close any open break/lunch at end of that day as well
                if ($entry->break_start && !$entry->break_end) {
                    $entry->break_end = Carbon::parse($entry->date)->endOfDay();
                }
                if ($entry->lunch_start && !$entry->lunch_end) {
                    $entry->lunch_end = Carbon::parse($entry->date)->endOfDay();
                }

                $entry->clock_out = Carbon::parse($entry->date)->endOfDay();
                $entry->total_hours = $this->calculateTotalHours($entry);
                $entry->save();
            }

            // Close any open task timers from previous days
            $openTasks = TimeEntry::where('user_id', $userId)
                ->whereNotNull('task_id')
                ->whereNull('clock_out')
                ->whereDate('created_at', '<', $today)
                ->get();

            foreach ($openTasks as $taskEntry) {
                $taskEntry->clock_out = Carbon::parse($taskEntry->date ?: $taskEntry->created_at)->endOfDay();
                $taskEntry->total_hours = $this->calculateTotalHours($taskEntry);
                $taskEntry->save();
            }
        }

        return $next($request);
    }

    private function calculateTotalHours(TimeEntry $entry): float
    {
        if (!$entry->clock_in || !$entry->clock_out) {
            return 0;
        }

        $clockIn = Carbon::parse($entry->clock_in);
        $clockOut = Carbon::parse($entry->clock_out);
        $totalMinutes = $clockOut->diffInMinutes($clockIn);

        if ($entry->break_start && $entry->break_end) {
            $breakStart = Carbon::parse($entry->break_start);
            $breakEnd = Carbon::parse($entry->break_end);
            $totalMinutes -= $breakEnd->diffInMinutes($breakStart);
        }

        if ($entry->lunch_start && $entry->lunch_end) {
            $lunchStart = Carbon::parse($entry->lunch_start);
            $lunchEnd = Carbon::parse($entry->lunch_end);
            $totalMinutes -= $lunchEnd->diffInMinutes($lunchStart);
        }

        return round(max(0, $totalMinutes) / 60, 2);
    }
}


