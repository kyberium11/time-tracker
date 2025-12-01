<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserActivityLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Get overview statistics.
     */
    public function overview(Request $request)
    {
        try {
            $period = $request->input('period', 'month'); // daily, weekly, monthly, ytd
            $date = now();
            
            switch ($period) {
                case 'daily':
                    $startDate = $date->copy()->startOfDay();
                    $endDate = $date->copy()->endOfDay();
                    break;
                case 'weekly':
                    $startDate = $date->copy()->startOfWeek();
                    $endDate = $date->copy()->endOfWeek();
                    break;
                case 'monthly':
                    $startDate = $date->copy()->startOfMonth();
                    $endDate = $date->copy()->endOfMonth();
                    break;
                case 'ytd':
                    $startDate = $date->copy()->startOfYear();
                    $endDate = $date->copy()->endOfDay();
                    break;
                default:
                    $startDate = $date->copy()->startOfMonth();
                    $endDate = $date->copy()->endOfMonth();
            }

            $user = Auth::user()->load('managedTeam');
            // If user is a manager, only show their team's data
            if ($user->role === 'manager') {
                if (!$user->managedTeam) {
                    // Manager without team, return empty data
                    return response()->json([
                        'period' => $period,
                        'date_range' => [
                            'start' => $startDate->format('Y-m-d'),
                            'end' => $endDate->format('Y-m-d'),
                        ],
                        'statistics' => [
                            'total_employees' => 0,
                            'total_hours' => 0,
                            'average_hours' => 0,
                            'total_entries' => 0,
                            'perfect_attendance_count' => 0,
                            'lates_count' => 0,
                            'undertime_count' => 0,
                            'overtime_count' => 0,
                        ],
                        'top_employees' => [],
                    ]);
                }
                
                $users = User::where('role', 'employee')->where('team_id', $user->managedTeam->id)->get();
                $userIds = $users->pluck('id')->toArray();
                $entries = TimeEntry::whereBetween('date', [$startDate, $endDate])
                    ->whereNotNull('clock_out')
                    ->whereIn('user_id', $userIds)
                    ->get();
            } elseif ($user->role === 'admin' || $user->role === 'developer') {
                // Admins and developers see all data
                $users = User::whereIn('role', ['employee', 'manager', 'developer'])->get();
                $entries = TimeEntry::whereBetween('date', [$startDate, $endDate])
                    ->whereNotNull('clock_out')
                    ->get();
            } else {
                // Employees see only their own data
                $users = collect([$user]);
                $entries = TimeEntry::where('user_id', $user->id)
                    ->whereBetween('date', [$startDate, $endDate])
                    ->whereNotNull('clock_out')
                    ->get();
            }

            // Use precise seconds for totals to avoid floating point/format issues
            $totalSeconds = $entries->sum(function ($entry) {
                $clockIn = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in) : null;
                $clockOut = $entry->clock_out ? \Carbon\Carbon::parse($entry->clock_out) : null;
                $seconds = 0;
                if ($clockIn && $clockOut) {
                    $seconds = max(0, $clockOut->diffInSeconds($clockIn));
                    if ($entry->break_start && $entry->break_end) {
                        $seconds -= max(0, \Carbon\Carbon::parse($entry->break_end)->diffInSeconds(\Carbon\Carbon::parse($entry->break_start)));
                    }
                    if ($entry->lunch_start && $entry->lunch_end) {
                        $seconds -= max(0, \Carbon\Carbon::parse($entry->lunch_end)->diffInSeconds(\Carbon\Carbon::parse($entry->lunch_start)));
                    }
                    $seconds = max(0, $seconds);
                }
                return $seconds;
            });

            $totalHours = round($totalSeconds / 3600, 2);
            $avgHours = $users->count() > 0 ? round(($totalSeconds / 3600) / $users->count(), 2) : 0;

            // Pre-format HMS for frontend display
            $h = intdiv($totalSeconds, 3600);
            $m = intdiv($totalSeconds % 3600, 60);
            $s = $totalSeconds % 60;
            $pad = function ($n) { return $n < 10 ? '0'.$n : (string) $n; };
            $totalHms = $pad($h).'h '.$pad($m).'m '.$pad($s).'s';
            
            // Calculate attendance metrics
            $totalDays = $users->count() > 0 ? $users->count() : 1;
            $daysWithEntries = $entries->groupBy('user_id')->count();
            $perfectAttendance = $daysWithEntries;
            
            // Calculate lates (clock in after 9 AM)
            $lates = $entries->filter(function($entry) {
                return $entry->clock_in && date('H:i', strtotime($entry->clock_in)) > '09:00';
            })->count();
            
            // Calculate undertime (< 8 hours)
            $undertime = $entries->filter(function($entry) {
                return $entry->total_hours < 8;
            })->count();
            
            // Calculate overtime (> 8 hours)
            $overtime = $entries->filter(function($entry) {
                return $entry->total_hours > 8;
            })->count();

            return response()->json([
                'period' => $period,
                'date_range' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                ],
                'statistics' => [
                    'total_employees' => $users->count(),
                    'total_hours' => $totalHours,
                    'total_hms' => $totalHms,
                    'average_hours' => $avgHours,
                    'total_entries' => $entries->count(),
                    'perfect_attendance_count' => $perfectAttendance,
                    'lates_count' => $lates,
                    'undertime_count' => $undertime,
                    'overtime_count' => $overtime,
                ],
                'top_employees' => $this->getTopEmployees($user, $startDate, $endDate),
            ]);
        } catch (\Exception $e) {
            \Log::error('Analytics overview error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
                'user_role' => Auth::user()->role ?? 'unknown',
            ]);
            return response()->json([
                'error' => 'Failed to load overview data',
                'details' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function workHourGaps(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user()->load('managedTeam');
        if (!in_array($user->role, ['admin', 'developer', 'manager'], true)) {
            return response()->json(['data' => []], 403);
        }

        $scopedUserIds = $this->resolveScopedUserIds($user);
        if ($scopedUserIds !== null && count($scopedUserIds) === 0) {
            return response()->json(['data' => []]);
        }

        $teamId = $request->input('team_id');
        $selectedUserId = $request->input('user_id');

        if ($teamId) {
            $teamUserQuery = User::where('team_id', $teamId);
            if ($scopedUserIds !== null) {
                $teamUserQuery->whereIn('id', $scopedUserIds);
            }
            $teamUserIds = $teamUserQuery->pluck('id')->all();

            $scopedUserIds = $teamUserIds;
        }

        if ($selectedUserId) {
            $selectedUserId = (int) $selectedUserId;
            if ($scopedUserIds !== null) {
                if (!in_array($selectedUserId, $scopedUserIds, true)) {
                    // Requested user is outside the allowed scope; return empty.
                    return response()->json(['data' => []]);
                }
                $scopedUserIds = [$selectedUserId];
            } else {
                $scopedUserIds = [$selectedUserId];
            }
        }

        if ($scopedUserIds !== null && count($scopedUserIds) === 0) {
            return response()->json(['data' => []]);
        }

        $startDateInput = $request->input('start_date', now()->copy()->subDays(30)->toDateString());
        $endDateInput = $request->input('end_date', now()->toDateString());
        $startDate = \Carbon\Carbon::parse($startDateInput)->startOfDay();
        $endDate = \Carbon\Carbon::parse($endDateInput)->endOfDay();

        $builder = TimeEntry::query()
            ->with(['user:id,name'])
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotNull('clock_in');

        if ($scopedUserIds !== null) {
            $builder->whereIn('user_id', $scopedUserIds);
        }

        $rows = $builder
            ->selectRaw("
                user_id,
                date,
                SUM(CASE WHEN task_id IS NULL THEN COALESCE(total_hours, 0) ELSE 0 END) AS worked_hours,
                SUM(CASE WHEN task_id IS NOT NULL THEN COALESCE(total_hours, 0) ELSE 0 END) AS task_hours
            ")
            ->groupBy('user_id', 'date')
            ->orderBy('date', 'desc')
            ->get();

        $data = $rows->map(function (TimeEntry $entry) {
            return [
                'user' => $entry->user?->name ?? 'Unknown',
                'date' => $entry->date,
                'worked_hours' => round((float) ($entry->worked_hours ?? 0), 2),
                'task_hours' => round((float) ($entry->task_hours ?? 0), 2),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function utilizationSummary(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user()->load('managedTeam');
        if (!in_array($user->role, ['admin', 'developer', 'manager'], true)) {
            return response()->json(['data' => []], 403);
        }

        $scopedUserIds = $this->resolveScopedUserIds($user);
        if ($scopedUserIds !== null && count($scopedUserIds) === 0) {
            return response()->json(['data' => []]);
        }

        $teamId = $request->input('team_id');
        $selectedUserId = $request->input('user_id');

        if ($teamId) {
            $teamUserQuery = User::where('team_id', $teamId);
            if ($scopedUserIds !== null) {
                $teamUserQuery->whereIn('id', $scopedUserIds);
            }
            $teamUserIds = $teamUserQuery->pluck('id')->all();

            $scopedUserIds = $teamUserIds;
        }

        if ($selectedUserId) {
            $selectedUserId = (int) $selectedUserId;
            if ($scopedUserIds !== null) {
                if (!in_array($selectedUserId, $scopedUserIds, true)) {
                    // Requested user is outside the allowed scope; return empty.
                    return response()->json(['data' => []]);
                }
                $scopedUserIds = [$selectedUserId];
            } else {
                $scopedUserIds = [$selectedUserId];
            }
        }

        if ($scopedUserIds !== null && count($scopedUserIds) === 0) {
            return response()->json(['data' => []]);
        }

        $startDateInput = $request->input('start_date', now()->copy()->subDays(30)->toDateString());
        $endDateInput = $request->input('end_date', now()->toDateString());
        $startDate = \Carbon\Carbon::parse($startDateInput)->startOfDay();
        $endDate = \Carbon\Carbon::parse($endDateInput)->endOfDay();

        $builder = TimeEntry::query()
            ->with(['user:id,name'])
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotNull('clock_in');

        if ($scopedUserIds !== null) {
            $builder->whereIn('user_id', $scopedUserIds);
        }

        $rows = $builder
            ->selectRaw("
                user_id,
                SUM(CASE WHEN task_id IS NULL THEN COALESCE(total_hours, 0) ELSE 0 END) AS worked_hours,
                SUM(CASE WHEN task_id IS NOT NULL THEN COALESCE(total_hours, 0) ELSE 0 END) AS task_hours
            ")
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->get();

        $data = $rows->map(function (TimeEntry $entry) {
            $worked = (float) ($entry->worked_hours ?? 0);
            $task = (float) ($entry->task_hours ?? 0);
            $percent = $worked > 0 ? round(($task / $worked) * 100, 2) : 0.0;

            return [
                'user' => $entry->user?->name ?? 'Unknown',
                'worked_hours' => round($worked, 2),
                'task_hours' => round($task, 2),
                'percent' => $percent,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function attendanceOverview(Request $request): \Illuminate\Http\JsonResponse
    {
        $authUser = Auth::user()->load('managedTeam');
        if (!in_array($authUser->role, ['admin', 'developer', 'manager'], true)) {
            return response()->json(['data' => []], 403);
        }

        $scopedUserIds = $this->resolveScopedUserIds($authUser);
        if ($scopedUserIds !== null && count($scopedUserIds) === 0) {
            return response()->json(['data' => []]);
        }

        $startDateInput = $request->input('start_date', now()->copy()->subDays(30)->toDateString());
        $endDateInput = $request->input('end_date', now()->toDateString());
        $startDate = \Carbon\Carbon::parse($startDateInput)->startOfDay();
        $endDate = \Carbon\Carbon::parse($endDateInput)->endOfDay();

        $usersQuery = User::query()->select(['id', 'name'])->whereIn('role', ['employee', 'manager', 'developer']);

        if ($scopedUserIds !== null) {
            $usersQuery->whereIn('id', $scopedUserIds);
        }

        if ($request->filled('user_id')) {
            $usersQuery->where('id', $request->integer('user_id'));
        }

        $users = $usersQuery->get();
        if ($users->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $userIds = $users->pluck('id')->all();

        $entries = TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotNull('clock_in')
            ->where(function ($query) {
                $query->whereNull('entry_type')->orWhere('entry_type', 'work');
            })
            ->get()
            ->groupBy(['user_id', 'date']);

        $totalDays = $startDate->diffInDays($endDate) + 1;

        $data = $users->map(function (User $user) use ($entries, $totalDays) {
            $userEntries = $entries->get($user->id, collect());
            $perfect = 0;
            $late = 0;
            $daysWithEntries = 0;

            foreach ($userEntries as $date => $dayEntries) {
                $daysWithEntries++;
                $earliest = $dayEntries->min('clock_in');
                $totalHours = $dayEntries->sum(function ($entry) {
                    return (float) ($entry->total_hours ?? 0);
                });

                if ($earliest) {
                    $clockIn = \Carbon\Carbon::parse($earliest);
                    $shiftStart = \Carbon\Carbon::parse($date . ' 09:00:00');
                    if ($clockIn->lte($shiftStart) && $totalHours >= 7.5) {
                        $perfect++;
                    } elseif ($clockIn->gt($shiftStart)) {
                        $late++;
                    }
                }
            }

            $absence = max(0, $totalDays - $daysWithEntries);

            return [
                'user' => $user->name,
                'perfect_days' => $perfect,
                'late_days' => $late,
                'absence_days' => $absence,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Simple health summary for super admins.
     */
    public function summary(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user()->load('managedTeam');

        if (!in_array($user->role, ['admin', 'developer', 'manager'], true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $today = now()->startOfDay();
        $endOfToday = $today->copy()->endOfDay();
        $startOfWeek = now()->copy()->startOfWeek();
        $endOfWeek = now()->copy()->endOfWeek();

        $scopedUserIds = $this->resolveScopedUserIds($user);
        if ($scopedUserIds !== null && count($scopedUserIds) === 0) {
            return response()->json([
                'generated_at' => now()->toIso8601String(),
                'dates' => [
                    'today' => $today->toDateString(),
                    'week_start' => $startOfWeek->toDateString(),
                    'week_end' => $endOfWeek->toDateString(),
                ],
                'totals' => [
                    'hours_today' => 0,
                    'hours_week' => 0,
                    'active_users_today' => 0,
                    'active_users_week' => 0,
                    'average_hours_per_user_week' => 0,
                    'open_entries' => 0,
                    'late_clock_ins_today' => 0,
                    'total_entries_today' => 0,
                ],
                'open_entries' => [],
            ]);
        }

        $hoursToday = $this->sumHours(
            $this->scopedEntriesBuilder($user, $scopedUserIds)
                ->whereBetween('date', [$today->toDateString(), $today->toDateString()])
                ->whereNotNull('clock_out')
        );

        $hoursWeek = $this->sumHours(
            $this->scopedEntriesBuilder($user, $scopedUserIds)
                ->whereBetween('date', [$startOfWeek->toDateString(), $endOfWeek->toDateString()])
                ->whereNotNull('clock_out')
        );

        $activeUsersToday = $this->scopedEntriesBuilder($user, $scopedUserIds)
            ->whereBetween('date', [$today->toDateString(), $today->toDateString()])
            ->whereNotNull('clock_in')
            ->distinct('user_id')
            ->count('user_id');

        $activeUsersWeek = $this->scopedEntriesBuilder($user, $scopedUserIds)
            ->whereBetween('date', [$startOfWeek->toDateString(), $endOfWeek->toDateString()])
            ->whereNotNull('clock_in')
            ->distinct('user_id')
            ->count('user_id');

        $totalEntriesToday = $this->scopedEntriesBuilder($user, $scopedUserIds)
            ->whereBetween('date', [$today->toDateString(), $today->toDateString()])
            ->whereNotNull('clock_out')
            ->count();

        $openEntriesQuery = $this->scopedEntriesBuilder($user, $scopedUserIds)
            ->with(['user:id,name,email,team_id', 'user.team:id,name'])
            ->whereNull('clock_out')
            ->whereNotNull('clock_in')
            ->orderByDesc('clock_in');

        $openEntriesCount = (clone $openEntriesQuery)->count();

        $openEntries = (clone $openEntriesQuery)
            ->limit(10)
            ->get()
            ->map(function (TimeEntry $entry) {
                $clockIn = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in) : null;
                $minutes = $clockIn ? max(0, $clockIn->diffInMinutes(now())) : null;

                return [
                    'entry_id' => $entry->id,
                    'user' => [
                        'id' => $entry->user?->id,
                        'name' => $entry->user?->name,
                        'email' => $entry->user?->email,
                        'team' => $entry->user?->team?->name,
                    ],
                    'clock_in' => $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in)->toIso8601String() : null,
                    'minutes_running' => $minutes,
                ];
            })
            ->values()
            ->all();

        $lateClockInsToday = $this->scopedEntriesBuilder($user, $scopedUserIds)
            ->whereBetween('date', [$today->toDateString(), $today->toDateString()])
            ->where('entry_type', 'work')
            ->whereNotNull('clock_in')
            ->whereTime('clock_in', '>', '09:00:00')
            ->count();

        $averageHoursPerUserWeek = $activeUsersWeek > 0
            ? round($hoursWeek / $activeUsersWeek, 2)
            : 0.0;

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'dates' => [
                'today' => $today->toDateString(),
                'week_start' => $startOfWeek->toDateString(),
                'week_end' => $endOfWeek->toDateString(),
            ],
            'totals' => [
                'hours_today' => round($hoursToday, 2),
                'hours_week' => round($hoursWeek, 2),
                'active_users_today' => $activeUsersToday,
                'active_users_week' => $activeUsersWeek,
                'average_hours_per_user_week' => $averageHoursPerUserWeek,
                'open_entries' => $openEntriesCount,
                'late_clock_ins_today' => $lateClockInsToday,
                'total_entries_today' => $totalEntriesToday,
            ],
            'open_entries' => $openEntries,
        ]);
    }

    private function scopedEntriesBuilder(User $user, ?array $scopedUserIds = null)
    {
        $builder = TimeEntry::query();

        if ($scopedUserIds === null) {
            return $builder;
        }

        return $builder->whereIn('user_id', $scopedUserIds);
    }

    private function resolveScopedUserIds(User $user): ?array
    {
        if (in_array($user->role, ['admin', 'developer'], true)) {
            return null;
        }

        if ($user->role === 'manager') {
            if (!$user->relationLoaded('managedTeam')) {
                $user->load('managedTeam');
            }

            if (!$user->managedTeam) {
                return [];
            }

            return User::where('team_id', $user->managedTeam->id)
                ->pluck('id')
                ->all();
        }

        return [$user->id];
    }

    private function sumHours($builder): float
    {
        return (float) $builder->sum(DB::raw('COALESCE(total_hours, 0)'));
    }

    private function resolveShiftWindow(User $user, $referenceDate): ?array
    {
        $user->loadMissing('shiftSchedules');
        $date = $referenceDate ? Carbon::parse($referenceDate) : now();

        $schedule = $user->shiftSchedules->firstWhere('day_of_week', $date->dayOfWeek);

        if ($schedule) {
            return $this->buildShiftWindowFromTimes($date, $schedule->start_time, $schedule->end_time);
        }

        if ($user->shift_start && $user->shift_end) {
            return $this->buildShiftWindowFromTimes($date, $user->shift_start, $user->shift_end);
        }

        return null;
    }

    private function buildShiftWindowFromTimes(Carbon $date, string $start, string $end): array
    {
        $startCarbon = Carbon::parse($date->copy()->format('Y-m-d') . ' ' . $start);
        $endCarbon = Carbon::parse($date->copy()->format('Y-m-d') . ' ' . $end);

        if ($endCarbon->lessThanOrEqualTo($startCarbon)) {
            $endCarbon->addDay();
        }

        return [
            'start' => $startCarbon,
            'end' => $endCarbon,
            'raw_start' => $start,
            'raw_end' => $end,
        ];
    }

    private function getShiftHours(?array $window): float
    {
        if (!$window) {
            return 8.0;
        }

        return $window['end']->diffInMinutes($window['start']) / 60;
    }


    /**
     * Get analytics data.
     */
    public function index(Request $request)
    {
        $startDate = $request->input('start_date', now()->startOfMonth());
        $endDate = $request->input('end_date', now()->endOfMonth());

        $analytics = User::with(['timeEntries' => function ($query) use ($startDate, $endDate) {
            $query->whereBetween('date', [$startDate, $endDate])
                  ->whereNotNull('clock_out');
        }])
        ->get()
        ->map(function ($user) {
            $totalHours = $user->timeEntries->sum('total_hours');
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'total_hours' => round($totalHours, 2),
                'entries_count' => $user->timeEntries->count(),
            ];
        })
        ->sortByDesc('total_hours')
        ->values();

        return response()->json([
            'data' => $analytics,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Get current user's own time entries (for employees).
     */
    public function myTimeEntries(Request $request)
    {
        $user = Auth::user();
        $startDate = $request->input('start_date', now()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));

        // Get work entries
        $workEntries = TimeEntry::with(['task'])
            ->where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('entry_type', 'work')
            ->whereNotNull('clock_in')
            ->orderBy('date', 'desc')
            ->orderBy('clock_in', 'asc')
            ->get();
        
        // Get break entries
        $breakEntries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('entry_type', 'break')
            ->whereNotNull('clock_in')
            ->orderBy('date', 'desc')
            ->orderBy('clock_in', 'asc')
            ->get();
        
        // Combine and sort by clock_in time
        $entries = $workEntries->concat($breakEntries)->sortBy(function($entry) {
            return $entry->clock_in ? $entry->clock_in->timestamp : 0;
        })->values();

        // Enrich per-entry precise seconds and HMS for frontend reliability
        // Format times in Asia/Manila timezone
        $timezone = 'Asia/Manila';
        $entries = $entries->map(function ($entry) use ($timezone) {
            $clockIn = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in)->setTimezone($timezone) : null;
            $clockOut = $entry->clock_out ? \Carbon\Carbon::parse($entry->clock_out)->setTimezone($timezone) : null;
            $breakStart = $entry->break_start ? \Carbon\Carbon::parse($entry->break_start)->setTimezone($timezone) : null;
            $breakEnd = $entry->break_end ? \Carbon\Carbon::parse($entry->break_end)->setTimezone($timezone) : null;
            $lunchStart = $entry->lunch_start ? \Carbon\Carbon::parse($entry->lunch_start)->setTimezone($timezone) : null;
            $lunchEnd = $entry->lunch_end ? \Carbon\Carbon::parse($entry->lunch_end)->setTimezone($timezone) : null;
            
            // Format times for display in Asia/Manila
            $entry->clock_in_formatted = $clockIn ? $clockIn->format('Y-m-d H:i:s') : null;
            $entry->clock_out_formatted = $clockOut ? $clockOut->format('Y-m-d H:i:s') : null;
            $entry->break_start_formatted = $breakStart ? $breakStart->format('Y-m-d H:i:s') : null;
            $entry->break_end_formatted = $breakEnd ? $breakEnd->format('Y-m-d H:i:s') : null;
            $entry->lunch_start_formatted = $lunchStart ? $lunchStart->format('Y-m-d H:i:s') : null;
            $entry->lunch_end_formatted = $lunchEnd ? $lunchEnd->format('Y-m-d H:i:s') : null;
            
            $seconds = 0;
            if ($clockIn && $clockOut) {
                // Use original UTC timestamps for accurate duration calculation
                $clockInUtc = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in) : null;
                $clockOutUtc = $entry->clock_out ? \Carbon\Carbon::parse($entry->clock_out) : null;
                if ($clockInUtc && $clockOutUtc) {
                    $seconds = max(0, $clockOutUtc->diffInSeconds($clockInUtc));
                    if ($entry->break_start && $entry->break_end) {
                        $bs = \Carbon\Carbon::parse($entry->break_start);
                        $be = \Carbon\Carbon::parse($entry->break_end);
                        $seconds -= max(0, $be->diffInSeconds($bs));
                    }
                    if ($entry->lunch_start && $entry->lunch_end) {
                        $ls = \Carbon\Carbon::parse($entry->lunch_start);
                        $le = \Carbon\Carbon::parse($entry->lunch_end);
                        $seconds -= max(0, $le->diffInSeconds($ls));
                    }
                    $seconds = max(0, $seconds);
                }
            }
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            $s = $seconds % 60;
            $pad = function ($n) { return $n < 10 ? '0'.$n : (string) $n; };
            $entry->duration_seconds = $seconds;
            $entry->duration_hms = $pad($h).'h '.$pad($m).'m '.$pad($s).'s';
            $entry->duration_hms_colon = $pad($h).':'.$pad($m).':'.$pad($s);
            return $entry;
        });

        // Calculate daily totals from filtered entries
        // Count all work entries (both with and without tasks) for total work time
        $workSeconds = 0;
        $breakSeconds = 0;
        $lunchSeconds = 0;
        $tasksCount = 0;
        $firstIn = null;
        $lastOut = null;
        
        foreach ($entries as $entry) {
            // Skip break entries in work calculation
            if ($entry->entry_type === 'break') {
                // Calculate break duration
                if ($entry->clock_in && $entry->clock_out) {
                    $clockInUtc = \Carbon\Carbon::parse($entry->clock_in);
                    $clockOutUtc = \Carbon\Carbon::parse($entry->clock_out);
                    $breakDur = max(0, $clockOutUtc->diffInSeconds($clockInUtc));
                    $breakSeconds += $breakDur;
                }
                continue;
            }
            
            // This is a work entry
            // Check if this entry has a task (task_id is set and task exists)
            $hasTask = $entry->task_id && $entry->task && ($entry->task->title || $entry->task->name);
            
            // Count all work entries (with or without tasks) for total work time
            // Include both closed entries and active entries (currently clocked in)
            if ($entry->clock_in && $entry->clock_out) {
                // Closed entry - use actual clock_out time
                $clockInUtc = \Carbon\Carbon::parse($entry->clock_in);
                $clockOutUtc = \Carbon\Carbon::parse($entry->clock_out);
                $seconds = max(0, $clockOutUtc->diffInSeconds($clockInUtc));
                
                // Add to work seconds (all work entries count, both with and without tasks)
                $workSeconds += $seconds;
                
                // Track first in and last out for status calculation
                if (!$firstIn || $clockInUtc->lt($firstIn)) {
                    $firstIn = $clockInUtc;
                }
                if (!$lastOut || $clockOutUtc->gt($lastOut)) {
                    $lastOut = $clockOutUtc;
                }
                
                // Subtract lunch from work hours (breaks are now separate entries)
                if ($entry->lunch_start && $entry->lunch_end) {
                    $ls = \Carbon\Carbon::parse($entry->lunch_start);
                    $le = \Carbon\Carbon::parse($entry->lunch_end);
                    $lunchDur = max(0, $le->diffInSeconds($ls));
                    $lunchSeconds += $lunchDur;
                    $workSeconds -= $lunchDur;
                }
            } elseif ($entry->clock_in && !$entry->clock_out) {
                // Entry is in progress - include current time in work seconds
                $clockInUtc = \Carbon\Carbon::parse($entry->clock_in);
                $now = \Carbon\Carbon::now('UTC');
                $seconds = max(0, $now->diffInSeconds($clockInUtc));
                
                // Add current time to work seconds
                $workSeconds += $seconds;
                
                // Track first in for status calculation
                if (!$firstIn || $clockInUtc->lt($firstIn)) {
                    $firstIn = $clockInUtc;
                }
                
                // If there's an active lunch, subtract current lunch time
                if ($entry->lunch_start && !$entry->lunch_end) {
                    $ls = \Carbon\Carbon::parse($entry->lunch_start);
                    $lunchDur = max(0, $now->diffInSeconds($ls));
                    $lunchSeconds += $lunchDur;
                    $workSeconds -= $lunchDur;
                } elseif ($entry->lunch_start && $entry->lunch_end) {
                    // Lunch completed - subtract total lunch time
                    $ls = \Carbon\Carbon::parse($entry->lunch_start);
                    $le = \Carbon\Carbon::parse($entry->lunch_end);
                    $lunchDur = max(0, $le->diffInSeconds($ls));
                    $lunchSeconds += $lunchDur;
                    $workSeconds -= $lunchDur;
                }
            }
            
            if ($hasTask) {
                $tasksCount++;
            }
        }
        
        $workSeconds = max(0, $workSeconds);
        
        // Determine status based on shift for the date
        $status = 'No Entry';
        $overtimeSeconds = 0;
        
        // Calculate status if we have at least a first clock in (even if still clocked in)
        if ($firstIn && count($entries) > 0) {
            // Get shift for the date (use the date from the request, not firstIn)
            $shift = $user->getShiftForDate($startDate);
            
            if ($shift && isset($shift['start']) && isset($shift['end'])) {
                // Parse shift times
                $startParts = explode(':', $shift['start']);
                $endParts = explode(':', $shift['end']);
                $startHour = (int) $startParts[0];
                $startMinute = isset($startParts[1]) ? (int) $startParts[1] : 0;
                $endHour = (int) $endParts[0];
                $endMinute = isset($endParts[1]) ? (int) $endParts[1] : 0;
                
                // Determine if shift spans midnight
                $spansMidnight = ($endHour < $startHour) || ($endHour == $startHour && $endMinute < $startMinute);
                
                // Calculate shift duration in hours
                $dateCarbon = Carbon::parse($startDate)->setTimezone('Asia/Manila')->startOfDay();
                $shiftStart = $dateCarbon->copy()->setTime($startHour, $startMinute, 0);
                if ($spansMidnight) {
                    $shiftEnd = $dateCarbon->copy()->addDay()->setTime($endHour, $endMinute, 0);
                } else {
                    $shiftEnd = $dateCarbon->copy()->setTime($endHour, $endMinute, 0);
                }
                $shiftHours = $shiftStart->diffInHours($shiftEnd);
                
                // Check if late (compare first clock in with shift start)
                $firstInLocal = $firstIn->copy()->setTimezone('Asia/Manila');
                $clockInTime = $firstInLocal->format('H:i');
                $expectedStart = $shiftStart->format('H:i');
                $isLate = $clockInTime > $expectedStart;
                
                // Check if has enough hours (within 0.5 hours tolerance)
                $hasEnoughHours = ($workSeconds / 3600) >= ($shiftHours - 0.5);
                
                if (!$isLate && $hasEnoughHours) {
                    $status = 'Perfect';
                } elseif ($isLate) {
                    $status = 'Late';
                } else {
                    $status = 'Undertime';
                }
                
                // Calculate overtime (work time exceeding shift hours)
                $overtimeSeconds = max(0, $workSeconds - ($shiftHours * 3600));
            } else {
                // No shift defined, use default 8 hours
                $shiftHours = 8;
                $hasEnoughHours = ($workSeconds / 3600) >= ($shiftHours - 0.5);
                $status = $hasEnoughHours ? 'Perfect' : 'Undertime';
                $overtimeSeconds = max(0, $workSeconds - ($shiftHours * 3600));
            }
        }

        // Format work seconds to HH:MM:SS for frontend
        $pad = function ($n) { return $n < 10 ? '0'.$n : (string) $n; };
        $h = intdiv($workSeconds, 3600);
        $m = intdiv($workSeconds % 3600, 60);
        $s = $workSeconds % 60;
        $workHoursFormatted = $pad($h).':'.$pad($m).':'.$pad($s);
        
        $breakH = intdiv($breakSeconds, 3600);
        $breakM = intdiv($breakSeconds % 3600, 60);
        $breakS = $breakSeconds % 60;
        $breakHoursFormatted = $pad($breakH).':'.$pad($breakM).':'.$pad($breakS);
        
        $overtimeH = intdiv($overtimeSeconds, 3600);
        $overtimeM = intdiv($overtimeSeconds % 3600, 60);
        $overtimeS = $overtimeSeconds % 60;
        $overtimeFormatted = $pad($overtimeH).':'.$pad($overtimeM).':'.$pad($overtimeS);
        
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'entries' => $entries,
            'summary' => [
                'total_hours' => round($workSeconds / 3600, 2),
                'total_entries' => $entries->count(),
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
            ],
            'daily_totals' => [
                'work_seconds' => $workSeconds,
                'work_hours_formatted' => $workHoursFormatted,
                'break_seconds' => $breakSeconds,
                'break_hours_formatted' => $breakHoursFormatted,
                'lunch_seconds' => $lunchSeconds,
                'tasks_count' => $tasksCount,
                'status' => $status,
                'overtime_seconds' => $overtimeSeconds,
                'overtime_formatted' => $overtimeFormatted,
            ],
        ]);
    }

    /**
     * Get user-specific analytics.
     */
    public function userAnalytics(Request $request, User $user)
    {
        $startDate = $request->input('start_date', now()->startOfMonth());
        $endDate = $request->input('end_date', now()->endOfMonth());

        // Get work entries
        $workEntries = TimeEntry::with(['task'])
            ->where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('entry_type', 'work')
            ->whereNotNull('clock_in')
            ->orderBy('date', 'desc')
            ->orderBy('clock_in', 'asc')
            ->get();
        
        // Get break entries
        $breakEntries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('entry_type', 'break')
            ->whereNotNull('clock_in')
            ->orderBy('date', 'desc')
            ->orderBy('clock_in', 'asc')
            ->get();
        
        // Combine and sort by clock_in time
        $entries = $workEntries->concat($breakEntries)->sortBy(function($entry) {
            return $entry->clock_in ? $entry->clock_in->timestamp : 0;
        })->values();

        // Enrich per-entry precise seconds and HMS for frontend reliability
        // Format times in Asia/Manila timezone
        $timezone = 'Asia/Manila';
        $entries = $entries->map(function ($entry) use ($timezone) {
            // For break entries, use clock_in/clock_out as break start/end
            if ($entry->entry_type === 'break') {
                $entry->is_break = true;
                $breakStart = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in)->setTimezone($timezone) : null;
                $breakEnd = $entry->clock_out ? \Carbon\Carbon::parse($entry->clock_out)->setTimezone($timezone) : null;
                $entry->clock_in_formatted = $breakStart ? $breakStart->format('Y-m-d H:i:s') : null;
                $entry->clock_out_formatted = $breakEnd ? $breakEnd->format('Y-m-d H:i:s') : null;
                $entry->break_start_formatted = null;
                $entry->break_end_formatted = null;
                $entry->lunch_start_formatted = null;
                $entry->lunch_end_formatted = null;
            } else {
                $entry->is_break = false;
                $clockIn = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in)->setTimezone($timezone) : null;
                $clockOut = $entry->clock_out ? \Carbon\Carbon::parse($entry->clock_out)->setTimezone($timezone) : null;
                $lunchStart = $entry->lunch_start ? \Carbon\Carbon::parse($entry->lunch_start)->setTimezone($timezone) : null;
                $lunchEnd = $entry->lunch_end ? \Carbon\Carbon::parse($entry->lunch_end)->setTimezone($timezone) : null;
                
                // Format times for display in Asia/Manila
                $entry->clock_in_formatted = $clockIn ? $clockIn->format('Y-m-d H:i:s') : null;
                $entry->clock_out_formatted = $clockOut ? $clockOut->format('Y-m-d H:i:s') : null;
                $entry->lunch_start_formatted = $lunchStart ? $lunchStart->format('Y-m-d H:i:s') : null;
                $entry->lunch_end_formatted = $lunchEnd ? $lunchEnd->format('Y-m-d H:i:s') : null;
                
                // Legacy break fields for backward compatibility (only for work entries)
                $breakStart = $entry->break_start ? \Carbon\Carbon::parse($entry->break_start)->setTimezone($timezone) : null;
                $breakEnd = $entry->break_end ? \Carbon\Carbon::parse($entry->break_end)->setTimezone($timezone) : null;
                $entry->break_start_formatted = $breakStart ? $breakStart->format('Y-m-d H:i:s') : null;
                $entry->break_end_formatted = $breakEnd ? $breakEnd->format('Y-m-d H:i:s') : null;
            }
            
            $seconds = 0;
            if ($entry->clock_in && $entry->clock_out) {
                // Use original UTC timestamps for accurate duration calculation
                $clockInUtc = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in) : null;
                $clockOutUtc = $entry->clock_out ? \Carbon\Carbon::parse($entry->clock_out) : null;
                if ($clockInUtc && $clockOutUtc) {
                    $seconds = max(0, $clockOutUtc->diffInSeconds($clockInUtc));
                    // For work entries, subtract lunch (breaks are now separate entries)
                    if ($entry->entry_type === 'work') {
                        if ($entry->lunch_start && $entry->lunch_end) {
                            $ls = \Carbon\Carbon::parse($entry->lunch_start);
                            $le = \Carbon\Carbon::parse($entry->lunch_end);
                            $seconds -= max(0, $le->diffInSeconds($ls));
                        }
                    }
                    $seconds = max(0, $seconds);
                }
            }
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            $s = $seconds % 60;
            $pad = function ($n) { return $n < 10 ? '0'.$n : (string) $n; };
            $entry->duration_seconds = $seconds;
            $entry->duration_hms = $pad($h).'h '.$pad($m).'m '.$pad($s).'s';
            $entry->duration_hms_colon = $pad($h).':'.$pad($m).':'.$pad($s);
            return $entry;
        });

        $totalHours = round($entries->sum(function($e){ return ($e->duration_seconds ?? 0) / 3600; }), 2);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'entries' => $entries,
            'summary' => [
                'total_hours' => round($totalHours, 2),
                'total_entries' => $entries->count(),
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
            ],
        ]);
    }

    /**
     * Time graph data for the authenticated user.
     *
     * Returns, for a short date range, the user's shift window (in local hours)
     * and any task-based work entries overlaid on top of that window.
     */
    public function myTimeGraph(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User $authUser */
        $authUser = Auth::user()->load('managedTeam');
        $timezone = 'Asia/Manila';

        // Determine which user's data to show.
        // By default, this is the authenticated user, but admins / managers / developers
        // can filter by a specific user_id (within their scope).
        $targetUser = $authUser;
        $scopedUserIds = $this->resolveScopedUserIds($authUser);

        if ($request->filled('user_id')) {
            $requestedId = (int) $request->input('user_id');

            if ($scopedUserIds !== null && !in_array($requestedId, $scopedUserIds, true)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

            $targetUser = User::findOrFail($requestedId);
        }

        $maxSpanDays = 31;
        $daysParam = (int) $request->input('days', 5);
        $daysParam = max(1, min(14, $daysParam));

        $startInput = $request->input('start_date');
        $endInput = $request->input('end_date');

        if ($startInput || $endInput) {
            $startDateLocal = $startInput
                ? Carbon::parse($startInput, $timezone)->startOfDay()
                : Carbon::parse($endInput, $timezone)->startOfDay()->copy()->subDays($daysParam - 1);

            $endDateLocal = $endInput
                ? Carbon::parse($endInput, $timezone)->startOfDay()
                : $startDateLocal->copy()->addDays($daysParam - 1);
        } else {
            $endDateLocal = Carbon::parse(now($timezone)->toDateString(), $timezone)->startOfDay();
            $startDateLocal = $endDateLocal->copy()->subDays($daysParam - 1);
        }

        if ($endDateLocal->lt($startDateLocal)) {
            [$startDateLocal, $endDateLocal] = [$endDateLocal, $startDateLocal];
        }

        if ($startDateLocal->diffInDays($endDateLocal) >= $maxSpanDays) {
            $endDateLocal = $startDateLocal->copy()->addDays($maxSpanDays - 1);
        }

        $startDate = $startDateLocal->toDateString();
        $endDate = $endDateLocal->toDateString();

        // Fetch all work entries with tasks in the date range
        $entries = TimeEntry::with('task')
            ->where('user_id', $targetUser->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('entry_type', 'work')
            ->whereNotNull('clock_in')
            ->whereNotNull('clock_out')
            ->orderBy('date')
            ->orderBy('clock_in')
            ->get()
            ->groupBy(function ($entry) {
                return Carbon::parse($entry->date)->toDateString();
            });

        $daysData = [];
        $cursor = $startDateLocal->copy();
        while ($cursor->lte($endDateLocal)) {
            $dateStr = $cursor->toDateString();

            // Determine shift for this date (if any)
            $shift = $targetUser->getShiftForDate($cursor);
            $shiftData = null;
            if ($shift && isset($shift['start'], $shift['end'])) {
                $startParts = explode(':', $shift['start']);
                $endParts = explode(':', $shift['end']);
                $startHour = (int) ($startParts[0] ?? 0);
                $startMinute = (int) ($startParts[1] ?? 0);
                $endHour = (int) ($endParts[0] ?? 0);
                $endMinute = (int) ($endParts[1] ?? 0);

                $startDecimal = $startHour + $startMinute / 60;
                $endDecimal = $endHour + $endMinute / 60;
                // If shift spans midnight, extend past 24h
                if ($endDecimal <= $startDecimal) {
                    $endDecimal += 24;
                }

                $shiftData = [
                    'start_hour' => $startDecimal,
                    'end_hour' => $endDecimal,
                ];
            }

            // Build work-hour and task segments for this date
            $taskSegments = [];
            $workSegments = [];
            /** @var \Illuminate\Support\Collection $dayEntries */
            $dayEntries = $entries->get($dateStr, collect());
            foreach ($dayEntries as $entry) {
                $cinLocal = Carbon::parse($entry->clock_in)->setTimezone($timezone);
                $coutLocal = Carbon::parse($entry->clock_out)->setTimezone($timezone);

                $startDecimal = $cinLocal->hour + $cinLocal->minute / 60;
                $endDecimal = $coutLocal->hour + $coutLocal->minute / 60;
                if ($endDecimal <= $startDecimal) {
                    $endDecimal += 24;
                }

                $workSegments[] = [
                    'title' => 'Work Hours',
                    'start_hour' => $startDecimal,
                    'end_hour' => $endDecimal,
                ];

                if ($entry->task && ($entry->task->title || $entry->task->name)) {
                    $taskSegments[] = [
                        'title' => $entry->task->title ?? $entry->task->name ?? 'Task',
                        'start_hour' => $startDecimal,
                        'end_hour' => $endDecimal,
                    ];
                }
            }

            $daysData[] = [
                'date' => $dateStr,
                'label' => $cursor->format('F j, Y'),
                'shift' => $shiftData,
                'work_hours' => $workSegments,
                'tasks' => $taskSegments,
            ];

            $cursor->addDay();
        }

        return response()->json([
            'timezone' => $timezone,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days' => $daysData,
        ]);
    }

    /**
     * Get all individual entries with filters.
     */
    public function individualEntries(Request $request)
    {
        $user = Auth::user()->load('managedTeam');
        $query = TimeEntry::with(['user', 'user.team', 'task']);

        // If user is a manager, only show their team's data
        if ($user->role === 'manager') {
            if (!$user->managedTeam) {
                // Manager without team, return empty data
                return response()->json([
                    'data' => [],
                    'current_page' => 1,
                    'total' => 0,
                    'per_page' => 50,
                ]);
            }
            
            $userIds = User::where('team_id', $user->managedTeam->id)
                ->whereIn('role', ['employee', 'developer'])
                ->pluck('id')
                ->toArray();
            $query->whereIn('user_id', $userIds);
        }

        // Filter by team if provided (admin and developer only)
        if ($request->has('team_id') && $request->team_id && ($user->role === 'admin' || $user->role === 'developer')) {
            $teamUserIds = User::where('team_id', $request->team_id)
                ->pluck('id')
                ->toArray();
            $query->whereIn('user_id', $teamUserIds);
        }

        // Filter by user if provided
        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->start_date) {
            $query->where('date', '>=', $request->start_date);
        }
        if ($request->has('end_date') && $request->end_date) {
            $query->where('date', '<=', $request->end_date);
        }

        // Filter by status - calculate based on user's shift time
        if ($request->has('status') && $request->status) {
            $entries = $query->with(['user.shiftSchedules'])->get();
            $filtered = $entries->filter(function($entry) use ($request) {
                $shiftWindow = $this->resolveShiftWindow($entry->user, $entry->date);
                if (!$shiftWindow) {
                    return false;
                }
                
                $clockIn = $entry->clock_in ? Carbon::parse($entry->clock_in) : null;
                if (!$clockIn) {
                    return false;
                }
                
                $shiftHours = $this->getShiftHours($shiftWindow);
                
                switch ($request->status) {
                    case 'late':
                        return $clockIn->greaterThan($shiftWindow['start']->copy()->addMinutes(5));
                    case 'undertime':
                        return $entry->total_hours < $shiftHours;
                    case 'overtime':
                        return $entry->total_hours > $shiftHours;
                    case 'perfect':
                        return $clockIn->lessThanOrEqualTo($shiftWindow['start']->copy()->addMinutes(5))
                            && $entry->total_hours >= $shiftHours - 0.5;
                    default:
                        return true;
                }
            });
            
            // Convert back to collection and paginate manually
            $page = $request->input('page', 1);
            $perPage = 50;
            $offset = ($page - 1) * $perPage;
            
            return response()->json([
                'data' => $filtered->sortByDesc('date')->values()->skip($offset)->take($perPage),
                'current_page' => $page,
                'total' => $filtered->count(),
                'per_page' => $perPage,
            ]);
        }

        $entries = $query->orderBy('date', 'desc')->paginate(50);

        // Enrich with precise duration in seconds and HMS for reliable display
        $entries = $entries->through(function ($entry) {
            $clockIn = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in) : null;
            $clockOut = $entry->clock_out ? \Carbon\Carbon::parse($entry->clock_out) : null;
            $seconds = 0;
            if ($clockIn && $clockOut) {
                $seconds = max(0, $clockOut->diffInSeconds($clockIn));
                // Subtract break
                if ($entry->break_start && $entry->break_end) {
                    $seconds -= max(0, \Carbon\Carbon::parse($entry->break_end)->diffInSeconds(\Carbon\Carbon::parse($entry->break_start)));
                }
                // Subtract lunch
                if ($entry->lunch_start && $entry->lunch_end) {
                    $seconds -= max(0, \Carbon\Carbon::parse($entry->lunch_end)->diffInSeconds(\Carbon\Carbon::parse($entry->lunch_start)));
                }
                $seconds = max(0, $seconds);
            }

            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            $s = $seconds % 60;
            $pad = function ($n) { return $n < 10 ? '0'.$n : (string) $n; };

            $entry->duration_seconds = $seconds;
            $entry->duration_hms = $pad($h).'h '.$pad($m).'m '.$pad($s).'s';
            $entry->duration_hms_colon = $pad($h).':'.$pad($m).':'.$pad($s);
            return $entry;
        });

        return response()->json($entries);
    }

    /**
     * Get list of all users for dropdown.
     */
    public function users()
    {
        $user = Auth::user()->load('managedTeam');
        
        // If user is a manager, only show their team's users
        if ($user->role === 'manager') {
            if (!$user->managedTeam) {
                // Manager without team, return empty data
                return response()->json([]);
            }
            
            $users = User::whereIn('role', ['employee', 'developer'])
                ->where('team_id', $user->managedTeam->id)
                ->get(['id', 'name', 'email']);
        } else {
            // Admins and developers see all roles in dropdown
            $users = User::whereIn('role', ['employee', 'manager', 'developer'])->get(['id', 'name', 'email']);
        }
        
        return response()->json($users);
    }

    /**
     * Export analytics data as CSV.
     */
    public function exportCsv(Request $request)
    {
        $period = $request->input('period', 'month');
        $startDate = $request->input('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->endOfMonth()->format('Y-m-d'));
        
        $entries = TimeEntry::with('user')
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('clock_out')
            ->orderBy('date', 'desc')
            ->get();

        $filename = 'analytics_' . now()->format('Y-m-d_His') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($entries) {
            $file = fopen('php://output', 'w');
            
            // Headers
            fputcsv($file, ['Employee', 'Date', 'Clock In', 'Clock Out', 'Break Duration', 'Lunch Duration', 'Total Hours']);
            
            // Data
            foreach ($entries as $entry) {
                $breakDuration = $this->calculateDuration($entry->break_start, $entry->break_end);
                $lunchDuration = $this->calculateDuration($entry->lunch_start, $entry->lunch_end);
                
                fputcsv($file, [
                    $entry->user->name ?? 'N/A',
                    $entry->date,
                    $entry->clock_in ? date('H:i:s', strtotime($entry->clock_in)) : 'N/A',
                    $entry->clock_out ? date('H:i:s', strtotime($entry->clock_out)) : 'N/A',
                    $breakDuration,
                    $lunchDuration,
                    $entry->total_hours,
                ]);
            }
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export analytics data as PDF.
     */
    public function exportPdf(Request $request)
    {
        $period = $request->input('period', 'month');
        $startDate = $request->input('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->endOfMonth()->format('Y-m-d'));
        
        $entries = TimeEntry::with('user')
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('clock_out')
            ->orderBy('date', 'desc')
            ->get();

        // For now, return as HTML that can be printed to PDF
        // You can integrate a PDF library like dompdf or TCPDF if needed
        $html = '<html><head><title>Analytics Report</title></head><body>';
        $html .= '<h1>Time Tracking Analytics Report</h1>';
        $html .= '<p>Period: ' . $startDate . ' to ' . $endDate . '</p>';
        $html .= '<table border="1" style="border-collapse: collapse; width: 100%;">';
        $html .= '<thead><tr><th>Employee</th><th>Date</th><th>Clock In</th><th>Clock Out</th><th>Total Hours</th></tr></thead><tbody>';
        
        foreach ($entries as $entry) {
            $html .= '<tr>';
            $html .= '<td>' . ($entry->user->name ?? 'N/A') . '</td>';
            $html .= '<td>' . $entry->date . '</td>';
            $html .= '<td>' . ($entry->clock_in ? date('H:i:s', strtotime($entry->clock_in)) : 'N/A') . '</td>';
            $html .= '<td>' . ($entry->clock_out ? date('H:i:s', strtotime($entry->clock_out)) : 'N/A') . '</td>';
            $html .= '<td>' . $entry->total_hours . 'h</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table></body></html>';

        return response($html)
            ->header('Content-Type', 'text/html')
            ->header('Content-Disposition', 'attachment; filename="analytics_' . now()->format('Y-m-d_His') . '.html"');
    }

    /**
     * Export user summary data as CSV.
     */
    public function exportUserSummaryCsv(Request $request)
    {
        $userId = $request->input('user_id');
        $startDate = $request->input('start_date', now()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));
        
        if (!$userId) {
            return response()->json(['error' => 'User ID is required'], 400);
        }
        
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        
        $entries = TimeEntry::with(['task'])
            ->where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('clock_out')
            ->orderBy('date', 'desc')
            ->orderBy('clock_in', 'asc')
            ->get();
        
        $timezone = 'Asia/Manila';
        $filename = 'user_summary_' . str_replace(' ', '_', $user->name) . '_' . $startDate . '_' . $endDate . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];
        
        $callback = function() use ($entries, $user, $timezone) {
            $file = fopen('php://output', 'w');
            
            // Add BOM for UTF-8 Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Headers
            fputcsv($file, ['Task', 'Start Time', 'End Time', 'Duration', 'Break Duration', 'Notes']);
            
            // Compute synthesized rows (Work Hours and Breaks)
            $firstIn = null; $lastOut = null; $totalBreakSeconds = 0; $rawWorkSeconds = 0;
            foreach ($entries as $e) {
                $cin = $e->clock_in ? \Carbon\Carbon::parse($e->clock_in) : null;
                $cout = $e->clock_out ? \Carbon\Carbon::parse($e->clock_out) : null;
                if ($cin && (!$firstIn || $cin->lt($firstIn))) { $firstIn = $cin; }
                if ($cout && (!$lastOut || $cout->gt($lastOut))) { $lastOut = $cout; }
                if ($cin && $cout) {
                    $rawWorkSeconds += max(0, $cout->diffInSeconds($cin));
                }
                if ($e->break_start && $e->break_end) {
                    $bs = \Carbon\Carbon::parse($e->break_start);
                    $be = \Carbon\Carbon::parse($e->break_end);
                    $totalBreakSeconds += max(0, $be->diffInSeconds($bs));
                }
            }
            $netWorkSeconds = max(0, $rawWorkSeconds - $totalBreakSeconds);
            $pad = function ($n) { return $n < 10 ? '0'.$n : (string)$n; };
            $fmtHms = function ($sec) use ($pad) { $h=intdiv($sec,3600); $m=intdiv($sec%3600,60); $s=$sec%60; return $pad($h).':'.$pad($m).':'.$pad($s); };

            // Row: Work Hours (overall)
            if ($firstIn && $lastOut) {
                $startTime = $firstIn->setTimezone($timezone)->format('h:i A');
                $endTime = $lastOut->setTimezone($timezone)->format('h:i A');
                fputcsv($file, [
                    'Work Hours',
                    $startTime,
                    $endTime,
                    $fmtHms($netWorkSeconds),
                    '00:00:00',
                    '-',
                ]);
            }

            // Data (task rows and Break rows)
            foreach ($entries as $entry) {
                $clockIn = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in)->setTimezone($timezone) : null;
                $clockOut = $entry->clock_out ? \Carbon\Carbon::parse($entry->clock_out)->setTimezone($timezone) : null;
                $breakStart = $entry->break_start ? \Carbon\Carbon::parse($entry->break_start)->setTimezone($timezone) : null;
                $breakEnd = $entry->break_end ? \Carbon\Carbon::parse($entry->break_end)->setTimezone($timezone) : null;
                
                $taskName = ($entry->task && ($entry->task->title || $entry->task->name)) 
                    ? ($entry->task->title ?? $entry->task->name) 
                    : 'Work';
                
                $startTime = $clockIn ? $clockIn->format('h:i A') : '--';
                $endTime = $clockOut ? $clockOut->format('h:i A') : '--';
                
                // Calculate duration
                $seconds = 0;
                if ($entry->clock_in && $entry->clock_out) {
                    $clockInUtc = \Carbon\Carbon::parse($entry->clock_in);
                    $clockOutUtc = \Carbon\Carbon::parse($entry->clock_out);
                    $seconds = max(0, $clockOutUtc->diffInSeconds($clockInUtc));
                    if ($entry->break_start && $entry->break_end) {
                        $bs = \Carbon\Carbon::parse($entry->break_start);
                        $be = \Carbon\Carbon::parse($entry->break_end);
                        $seconds -= max(0, $be->diffInSeconds($bs));
                    }
                    if ($entry->lunch_start && $entry->lunch_end) {
                        $ls = \Carbon\Carbon::parse($entry->lunch_start);
                        $le = \Carbon\Carbon::parse($entry->lunch_end);
                        $seconds -= max(0, $le->diffInSeconds($ls));
                    }
                    $seconds = max(0, $seconds);
                }
                $h = intdiv($seconds, 3600);
                $m = intdiv($seconds % 3600, 60);
                $s = $seconds % 60;
                $pad = function ($n) { return $n < 10 ? '0'.$n : (string) $n; };
                $duration = $pad($h).':'.$pad($m).':'.$pad($s);
                
                // Calculate break duration
                $breakSeconds = 0;
                if ($entry->break_start && $entry->break_end) {
                    $bs = \Carbon\Carbon::parse($entry->break_start);
                    $be = \Carbon\Carbon::parse($entry->break_end);
                    $breakSeconds = max(0, $be->diffInSeconds($bs));
                }
                $bh = intdiv($breakSeconds, 3600);
                $bm = intdiv($breakSeconds % 3600, 60);
                $bs = $breakSeconds % 60;
                $breakDuration = $breakSeconds > 0 ? $pad($bh).':'.$pad($bm).':'.$pad($bs) : '00:00:00';
                
                $notes = ($entry->lunch_start && $entry->lunch_end) ? 'Lunch' : '-';
                
                fputcsv($file, [
                    $taskName,
                    $startTime,
                    $endTime,
                    $duration,
                    $breakDuration,
                    $notes,
                ]);
                // Add a distinct Break row mirroring UI when break segment exists
                if ($breakStart && $breakEnd) {
                    $bdSec = max(0, \Carbon\Carbon::parse($entry->break_end)->diffInSeconds(\Carbon\Carbon::parse($entry->break_start)));
                    fputcsv($file, [
                        'Break',
                        $breakStart->format('h:i A'),
                        $breakEnd->format('h:i A'),
                        $fmtHms($bdSec),
                        $fmtHms($bdSec),
                        '-',
                    ]);
                }
            }
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }
    
    /**
     * Export user summary data as PDF.
     */
    public function exportUserSummaryPdf(Request $request)
    {
        $userId = $request->input('user_id');
        $startDate = $request->input('start_date', now()->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));
        
        if (!$userId) {
            return response()->json(['error' => 'User ID is required'], 400);
        }
        
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        
        $entries = TimeEntry::with(['task'])
            ->where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('clock_out')
            ->orderBy('date', 'desc')
            ->orderBy('clock_in', 'asc')
            ->get();
        
        $timezone = 'Asia/Manila';
        $filename = 'user_summary_' . str_replace(' ', '_', $user->name) . '_' . $startDate . '_' . $endDate . '.html';
        
        // Calculate daily totals
        $workSeconds = 0;
        $breakSeconds = 0;
        $lunchSeconds = 0;
        $tasksCount = 0;
        
        foreach ($entries as $entry) {
            if ($entry->clock_in && $entry->clock_out) {
                $clockInUtc = \Carbon\Carbon::parse($entry->clock_in);
                $clockOutUtc = \Carbon\Carbon::parse($entry->clock_out);
                $seconds = max(0, $clockOutUtc->diffInSeconds($clockInUtc));
                $workSeconds += $seconds;
                
                if ($entry->break_start && $entry->break_end) {
                    $bs = \Carbon\Carbon::parse($entry->break_start);
                    $be = \Carbon\Carbon::parse($entry->break_end);
                    $breakSeconds += max(0, $be->diffInSeconds($bs));
                    $workSeconds -= max(0, $be->diffInSeconds($bs));
                }
                
                if ($entry->lunch_start && $entry->lunch_end) {
                    $ls = \Carbon\Carbon::parse($entry->lunch_start);
                    $le = \Carbon\Carbon::parse($entry->lunch_end);
                    $lunchSeconds += max(0, $le->diffInSeconds($ls));
                    $workSeconds -= max(0, $le->diffInSeconds($ls));
                }
            }
            if ($entry->task && ($entry->task->title || $entry->task->name)) {
                $tasksCount++;
            }
        }
        
        $formatSeconds = function($sec) {
            $h = intdiv($sec, 3600);
            $m = intdiv($sec % 3600, 60);
            $s = $sec % 60;
            $pad = function ($n) { return $n < 10 ? '0'.$n : (string) $n; };
            return $pad($h).':'.$pad($m).':'.$pad($s);
        };
        
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>User Summary Report</title>';
        $html .= '<style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #333; }
            h2 { color: #666; margin-top: 20px; }
            .summary { background: #f5f5f5; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
            .summary-item { display: inline-block; margin-right: 30px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #4CAF50; color: white; }
            tr:nth-child(even) { background-color: #f2f2f2; }
        </style></head><body>';
        $html .= '<h1>User Summary Report</h1>';
        $html .= '<p><strong>User:</strong> ' . htmlspecialchars($user->name) . ' (' . htmlspecialchars($user->email) . ')</p>';
        $html .= '<p><strong>Period:</strong> ' . htmlspecialchars($startDate) . ' to ' . htmlspecialchars($endDate) . '</p>';
        $html .= '<div class="summary">';
        $html .= '<div class="summary-item"><strong>Time In Hours:</strong> ' . $formatSeconds($workSeconds) . '</div>';
        $html .= '<div class="summary-item"><strong>Total Breaks:</strong> ' . $formatSeconds($breakSeconds) . '</div>';
        $html .= '<div class="summary-item"><strong>Total Lunch:</strong> ' . $formatSeconds($lunchSeconds) . '</div>';
        $html .= '<div class="summary-item"><strong>Tasks Done:</strong> ' . $tasksCount . '</div>';
        $html .= '</div>';
        $html .= '<h2>Task Details</h2>';
        $html .= '<table><thead><tr><th>Task</th><th>Start Time</th><th>End Time</th><th>Duration</th><th>Break Duration</th><th>Notes</th></tr></thead><tbody>';

        // Synthesize Work Hours row (overall)
        $firstIn = null; $lastOut = null; $totalBreakSecondsRow = 0; $rawWorkSecondsRow = 0;
        foreach ($entries as $e) {
            $cin = $e->clock_in ? \Carbon\Carbon::parse($e->clock_in) : null;
            $cout = $e->clock_out ? \Carbon\Carbon::parse($e->clock_out) : null;
            if ($cin && (!$firstIn || $cin->lt($firstIn))) { $firstIn = $cin; }
            if ($cout && (!$lastOut || $cout->gt($lastOut))) { $lastOut = $cout; }
            if ($cin && $cout) { $rawWorkSecondsRow += max(0, $cout->diffInSeconds($cin)); }
            if ($e->break_start && $e->break_end) {
                $bs = \Carbon\Carbon::parse($e->break_start);
                $be = \Carbon\Carbon::parse($e->break_end);
                $totalBreakSecondsRow += max(0, $be->diffInSeconds($bs));
            }
        }
        $netWorkSecondsRow = max(0, $rawWorkSecondsRow - $totalBreakSecondsRow);
        $pad = function ($n) { return $n < 10 ? '0'.$n : (string)$n; };
        $fmtHms = function ($sec) use ($pad) { $h=intdiv($sec,3600); $m=intdiv($sec%3600,60); $s=$sec%60; return $pad($h).':'.$pad($m).':'.$pad($s); };
        if ($firstIn && $lastOut) {
            $html .= '<tr>';
            $html .= '<td>Work Hours</td>';
            $html .= '<td>' . $firstIn->setTimezone($timezone)->format('h:i A') . '</td>';
            $html .= '<td>' . $lastOut->setTimezone($timezone)->format('h:i A') . '</td>';
            $html .= '<td>' . $fmtHms($netWorkSecondsRow) . '</td>';
            $html .= '<td>00:00:00</td>';
            $html .= '<td>-</td>';
            $html .= '</tr>';
        }
        
        foreach ($entries as $entry) {
            $clockIn = $entry->clock_in ? \Carbon\Carbon::parse($entry->clock_in)->setTimezone($timezone) : null;
            $clockOut = $entry->clock_out ? \Carbon\Carbon::parse($entry->clock_out)->setTimezone($timezone) : null;
            $breakStart = $entry->break_start ? \Carbon\Carbon::parse($entry->break_start)->setTimezone($timezone) : null;
            $breakEnd = $entry->break_end ? \Carbon\Carbon::parse($entry->break_end)->setTimezone($timezone) : null;
            
            $taskName = ($entry->task && ($entry->task->title || $entry->task->name)) 
                ? htmlspecialchars($entry->task->title ?? $entry->task->name) 
                : 'Work';
            
            $startTime = $clockIn ? $clockIn->format('h:i A') : '--';
            $endTime = $clockOut ? $clockOut->format('h:i A') : '--';
            
            // Calculate duration
            $seconds = 0;
            if ($entry->clock_in && $entry->clock_out) {
                $clockInUtc = \Carbon\Carbon::parse($entry->clock_in);
                $clockOutUtc = \Carbon\Carbon::parse($entry->clock_out);
                $seconds = max(0, $clockOutUtc->diffInSeconds($clockInUtc));
                if ($entry->break_start && $entry->break_end) {
                    $bs = \Carbon\Carbon::parse($entry->break_start);
                    $be = \Carbon\Carbon::parse($entry->break_end);
                    $seconds -= max(0, $be->diffInSeconds($bs));
                }
                if ($entry->lunch_start && $entry->lunch_end) {
                    $ls = \Carbon\Carbon::parse($entry->lunch_start);
                    $le = \Carbon\Carbon::parse($entry->lunch_end);
                    $seconds -= max(0, $le->diffInSeconds($ls));
                }
                $seconds = max(0, $seconds);
            }
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            $s = $seconds % 60;
            $pad = function ($n) { return $n < 10 ? '0'.$n : (string) $n; };
            $duration = $pad($h).':'.$pad($m).':'.$pad($s);
            
            // Calculate break duration
            $breakSeconds = 0;
            if ($entry->break_start && $entry->break_end) {
                $bs = \Carbon\Carbon::parse($entry->break_start);
                $be = \Carbon\Carbon::parse($entry->break_end);
                $breakSeconds = max(0, $be->diffInSeconds($bs));
            }
            $bh = intdiv($breakSeconds, 3600);
            $bm = intdiv($breakSeconds % 3600, 60);
            $bs = $breakSeconds % 60;
            $breakDuration = $breakSeconds > 0 ? $pad($bh).':'.$pad($bm).':'.$pad($bs) : '00:00:00';
            
            $notes = ($entry->lunch_start && $entry->lunch_end) ? 'Lunch' : '-';
            
            $html .= '<tr>';
            $html .= '<td>' . $taskName . '</td>';
            $html .= '<td>' . $startTime . '</td>';
            $html .= '<td>' . $endTime . '</td>';
            $html .= '<td>' . $duration . '</td>';
            $html .= '<td>' . $breakDuration . '</td>';
            $html .= '<td>' . $notes . '</td>';
            $html .= '</tr>';

            // Add Break row mirroring UI when present
            if ($entry->break_start && $entry->break_end) {
                $bdSec = max(0, \Carbon\Carbon::parse($entry->break_end)->diffInSeconds(\Carbon\Carbon::parse($entry->break_start)));
                $html .= '<tr>';
                $html .= '<td>Break</td>';
                $html .= '<td>' . ($breakStart ? $breakStart->format('h:i A') : '--') . '</td>';
                $html .= '<td>' . ($breakEnd ? $breakEnd->format('h:i A') : '--') . '</td>';
                $html .= '<td>' . $fmtHms($bdSec) . '</td>';
                $html .= '<td>' . $fmtHms($bdSec) . '</td>';
                $html .= '<td>-</td>';
                $html .= '</tr>';
            }
        }
        
        $html .= '</tbody></table></body></html>';
        
        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
    
    /**
     * Calculate duration between two times in minutes.
     */
    private function calculateDuration($start, $end): string
    {
        if (!$start || !$end) return '--';
        
        $startTime = \Carbon\Carbon::parse($start);
        $endTime = \Carbon\Carbon::parse($end);
        $minutes = $endTime->diffInMinutes($startTime);
        
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        
        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }
        return "{$mins}m";
    }

    /**
     * Get activity logs (real-time events).
     */
    public function activityLogs(Request $request)
    {
        $user = Auth::user()->load('managedTeam');
        $query = UserActivityLog::with('user')
            ->orderBy('created_at', 'desc');

        // If user is a manager, only show their team's logs
        if ($user->role === 'manager') {
            if (!$user->managedTeam) {
                // Manager without team, return empty data
                return response()->json([
                    'data' => [],
                    'count' => 0,
                ]);
            }
            
            $userIds = User::where('team_id', $user->managedTeam->id)
                ->where('role', 'employee')
                ->pluck('id')
                ->toArray();
            $query->whereIn('user_id', $userIds);
        }

        // Filter by user if provided
        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by action if provided
        if ($request->has('action') && $request->action) {
            $query->where('action', $request->action);
        }

        // Limit to last N records (default 100)
        $limit = $request->input('limit', 100);
        $query->limit($limit);

        $logs = $query->get();

        return response()->json([
            'data' => $logs,
            'count' => $logs->count(),
        ]);
    }

    /**
     * Get top employees based on role (filtered by team for managers).
     */
    private function getTopEmployees($user, $startDate, $endDate)
    {
        $query = User::with(['timeEntries' => function ($query) use ($startDate, $endDate) {
            $query->whereBetween('date', [$startDate, $endDate])
                  ->whereNotNull('clock_out');
        }])
        ->whereIn('role', ['employee', 'manager', 'developer']);

        // If user is a manager, filter by their team
        if ($user->role === 'manager' && $user->managedTeam) {
            $query->where('team_id', $user->managedTeam->id);
        }

        return $query->get()
            ->map(function ($user) {
                $totalHours = $user->timeEntries->sum('total_hours');
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'total_hours' => round($totalHours, 2),
                    'entries_count' => $user->timeEntries->count(),
                ];
            })
            ->sortByDesc('total_hours')
            ->take(10)
            ->values();
    }

    /**
     * Get efficiency analytics data.
     */
    public function efficiency(Request $request)
    {
        try {
            // Get filters from request
            $userId = $request->input('user_id');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            
            // Build query for tasks with estimated time
            $tasksQuery = Task::whereNotNull('estimated_time');
            
            // Filter by user if provided
            if ($userId) {
                $tasksQuery->where('user_id', $userId);
            }
            
            // Only include tasks that have time entries within the date range (if dates provided)
            if ($startDate || $endDate) {
                $tasksQuery->whereHas('timeEntries', function ($query) use ($startDate, $endDate) {
                    $query->whereNotNull('clock_out');
                    if ($startDate) {
                        $query->whereDate('date', '>=', $startDate);
                    }
                    if ($endDate) {
                        $query->whereDate('date', '<=', $endDate);
                    }
                });
            } else {
                // If no date filter, only include tasks with at least one completed time entry
                $tasksQuery->whereHas('timeEntries', function ($query) {
                    $query->whereNotNull('clock_out');
                });
            }
            
            // Filter time entries by date range if provided
            $timeEntriesQuery = function ($query) use ($startDate, $endDate) {
                $query->whereNotNull('clock_out');
                if ($startDate) {
                    $query->whereDate('date', '>=', $startDate);
                }
                if ($endDate) {
                    $query->whereDate('date', '<=', $endDate);
                }
            };
            
            $tasks = $tasksQuery->with(['user', 'timeEntries' => $timeEntriesQuery])->get();

            $taskData = [];
            $totalEstimatedHours = 0;
            $totalTrackedHours = 0;
            $tasksWithOverrun = 0;
            $efficiencySum = 0;
            $validTasksCount = 0;

            // Process each task
            foreach ($tasks as $task) {
                // Convert estimated_time from milliseconds to hours
                $estimatedHours = $task->estimated_time / (1000 * 60 * 60);
                
                // Calculate tracked time from time entries
                // Filter time entries that have total_hours > 0
                $trackedHours = $task->timeEntries
                    ->where('total_hours', '>', 0)
                    ->sum('total_hours') ?? 0;
                
                // If trackedHours is still 0, try calculating from clock_in/clock_out
                if ($trackedHours == 0 && $task->timeEntries->count() > 0) {
                    $trackedHours = $task->timeEntries->sum(function ($entry) {
                        if (!$entry->clock_in || !$entry->clock_out) {
                            return $entry->total_hours ?? 0;
                        }
                        $clockIn = \Carbon\Carbon::parse($entry->clock_in);
                        $clockOut = \Carbon\Carbon::parse($entry->clock_out);
                        $minutes = $clockOut->diffInMinutes($clockIn);
                        
                        // Subtract breaks
                        if ($entry->break_start && $entry->break_end) {
                            $breakStart = \Carbon\Carbon::parse($entry->break_start);
                            $breakEnd = \Carbon\Carbon::parse($entry->break_end);
                            $minutes -= $breakStart->diffInMinutes($breakEnd);
                        }
                        
                        // Subtract lunch
                        if ($entry->lunch_start && $entry->lunch_end) {
                            $lunchStart = \Carbon\Carbon::parse($entry->lunch_start);
                            $lunchEnd = \Carbon\Carbon::parse($entry->lunch_end);
                            $minutes -= $lunchStart->diffInMinutes($lunchEnd);
                        }
                        
                        return max(0, round($minutes / 60, 2));
                    });
                }
                
                // Calculate efficiency: (EstimatedTime / TrackedTime) * 100
                // If tracked time is 0, efficiency is undefined
                $efficiency = $trackedHours > 0 ? ($estimatedHours / $trackedHours) * 100 : null;
                
                // Determine status
                $status = 'Efficient';
                if ($efficiency !== null) {
                    if ($efficiency < 90) {
                        $status = 'Overrun';
                        $tasksWithOverrun++;
                    } elseif ($efficiency < 100) {
                        $status = 'At Risk';
                    }
                }

                // For summary calculations, only include tasks with tracked time
                if ($trackedHours > 0 && $efficiency !== null) {
                    $totalEstimatedHours += $estimatedHours;
                    $totalTrackedHours += $trackedHours;
                    $efficiencySum += $efficiency;
                    $validTasksCount++;
                }

                $taskData[] = [
                    'id' => $task->id,
                    'task_name' => $task->title,
                    'assigned_to' => $task->user ? $task->user->name : 'Unassigned',
                    'project' => $task->clickup_parent_id ? "Project #{$task->clickup_parent_id}" : 'N/A',
                    'estimated_time' => round($estimatedHours, 2),
                    'tracked_time' => round($trackedHours, 2),
                    'efficiency' => $efficiency !== null ? round($efficiency, 2) : null,
                    'status' => $status,
                ];
            }

            // Calculate summary statistics
            $averageEfficiency = $validTasksCount > 0 ? $efficiencySum / $validTasksCount : 0;
            $overrunRate = count($tasks) > 0 ? ($tasksWithOverrun / count($tasks)) * 100 : 0;

            // Prepare user efficiency data for bar chart
            $userEfficiency = [];
            $userData = [];
            foreach ($tasks as $task) {
                if (!$task->user) continue;
                
                // Calculate tracked hours with fallback
                $trackedHours = $task->timeEntries
                    ->where('total_hours', '>', 0)
                    ->sum('total_hours') ?? 0;
                
                // If trackedHours is still 0, try calculating from clock_in/clock_out
                if ($trackedHours == 0 && $task->timeEntries->count() > 0) {
                    $trackedHours = $task->timeEntries->sum(function ($entry) {
                        if (!$entry->clock_in || !$entry->clock_out) {
                            return $entry->total_hours ?? 0;
                        }
                        $clockIn = \Carbon\Carbon::parse($entry->clock_in);
                        $clockOut = \Carbon\Carbon::parse($entry->clock_out);
                        $minutes = $clockOut->diffInMinutes($clockIn);
                        
                        if ($entry->break_start && $entry->break_end) {
                            $breakStart = \Carbon\Carbon::parse($entry->break_start);
                            $breakEnd = \Carbon\Carbon::parse($entry->break_end);
                            $minutes -= $breakStart->diffInMinutes($breakEnd);
                        }
                        
                        if ($entry->lunch_start && $entry->lunch_end) {
                            $lunchStart = \Carbon\Carbon::parse($entry->lunch_start);
                            $lunchEnd = \Carbon\Carbon::parse($entry->lunch_end);
                            $minutes -= $lunchStart->diffInMinutes($lunchEnd);
                        }
                        
                        return max(0, round($minutes / 60, 2));
                    });
                }
                
                // Only include tasks with tracked time in the date range
                if ($trackedHours <= 0) continue;
                
                $userId = $task->user->id;
                $userName = $task->user->name;
                
                if (!isset($userData[$userId])) {
                    $userData[$userId] = [
                        'name' => $userName,
                        'estimated' => 0,
                        'tracked' => 0,
                    ];
                }
                
                $estimatedHours = $task->estimated_time / (1000 * 60 * 60);
                
                $userData[$userId]['estimated'] += $estimatedHours;
                $userData[$userId]['tracked'] += $trackedHours;
            }

            foreach ($userData as $userId => $data) {
                $efficiency = $data['tracked'] > 0 ? ($data['estimated'] / $data['tracked']) * 100 : 0;
                $userEfficiency[] = [
                    'user' => $data['name'],
                    'efficiency' => round($efficiency, 2),
                ];
            }

            // Sort by efficiency descending
            usort($userEfficiency, function ($a, $b) {
                return $b['efficiency'] <=> $a['efficiency'];
            });

            // Prepare trend data (average efficiency over time)
            // Use the filtered date range if provided, otherwise use last 12 weeks
            $trendData = [];
            
            if ($startDate && $endDate) {
                // Use the provided date range and group by week
                $start = \Carbon\Carbon::parse($startDate)->startOfWeek();
                $endDateObj = \Carbon\Carbon::parse($endDate);
                $end = $endDateObj->copy()->endOfWeek();
                
                $current = $start->copy();
                while ($current <= $end) {
                    $weekStart = $current->copy()->startOfWeek();
                    $weekEnd = $current->copy()->endOfWeek();
                    
                    // Don't go beyond the end date
                    if ($weekStart > $endDateObj) break;
                    if ($weekEnd > $endDateObj) $weekEnd = $endDateObj->copy();
                    
                    $weekTasksQuery = Task::whereNotNull('estimated_time');
                    
                    // Apply user filter if provided
                    if ($userId) {
                        $weekTasksQuery->where('user_id', $userId);
                    }
                    
                    $weekTasks = $weekTasksQuery
                        ->whereHas('timeEntries', function ($query) use ($weekStart, $weekEnd) {
                            $query->whereNotNull('clock_out')
                                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()]);
                        })
                        ->with(['timeEntries' => function ($query) use ($weekStart, $weekEnd) {
                            $query->whereNotNull('clock_out')
                                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()]);
                        }])
                        ->get();

                    $weekEstimated = 0;
                    $weekTracked = 0;
                    $weekValidCount = 0;

                    foreach ($weekTasks as $task) {
                        $estimatedHours = $task->estimated_time / (1000 * 60 * 60);
                        
                        // Calculate tracked hours with fallback
                        $trackedHours = $task->timeEntries
                            ->where('total_hours', '>', 0)
                            ->sum('total_hours') ?? 0;
                        
                        // If trackedHours is still 0, try calculating from clock_in/clock_out
                        if ($trackedHours == 0 && $task->timeEntries->count() > 0) {
                            $trackedHours = $task->timeEntries->sum(function ($entry) {
                                if (!$entry->clock_in || !$entry->clock_out) {
                                    return $entry->total_hours ?? 0;
                                }
                                $clockIn = \Carbon\Carbon::parse($entry->clock_in);
                                $clockOut = \Carbon\Carbon::parse($entry->clock_out);
                                $minutes = $clockOut->diffInMinutes($clockIn);
                                
                                if ($entry->break_start && $entry->break_end) {
                                    $breakStart = \Carbon\Carbon::parse($entry->break_start);
                                    $breakEnd = \Carbon\Carbon::parse($entry->break_end);
                                    $minutes -= $breakStart->diffInMinutes($breakEnd);
                                }
                                
                                if ($entry->lunch_start && $entry->lunch_end) {
                                    $lunchStart = \Carbon\Carbon::parse($entry->lunch_start);
                                    $lunchEnd = \Carbon\Carbon::parse($entry->lunch_end);
                                    $minutes -= $lunchStart->diffInMinutes($lunchEnd);
                                }
                                
                                return max(0, round($minutes / 60, 2));
                            });
                        }
                        
                        if ($trackedHours > 0) {
                            $weekEstimated += $estimatedHours;
                            $weekTracked += $trackedHours;
                            $weekValidCount++;
                        }
                    }

                    $weekEfficiency = $weekTracked > 0 && $weekValidCount > 0 
                        ? ($weekEstimated / $weekTracked) * 100 
                        : null;

                    $trendData[] = [
                        'week' => $weekStart->format('M d'),
                        'efficiency' => $weekEfficiency !== null ? round($weekEfficiency, 2) : null,
                    ];
                    
                    $current->addWeek();
                }
            } else {
                // Default to last 12 weeks if no date range provided
                for ($i = 11; $i >= 0; $i--) {
                    $weekStart = now()->subWeeks($i)->startOfWeek();
                    $weekEnd = $weekStart->copy()->endOfWeek();
                    
                    $weekTasksQuery = Task::whereNotNull('estimated_time');
                    
                    // Apply user filter if provided
                    if ($userId) {
                        $weekTasksQuery->where('user_id', $userId);
                    }
                    
                    $weekTasks = $weekTasksQuery
                        ->whereHas('timeEntries', function ($query) use ($weekStart, $weekEnd) {
                            $query->whereNotNull('clock_out')
                                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()]);
                        })
                        ->with(['timeEntries' => function ($query) use ($weekStart, $weekEnd) {
                            $query->whereNotNull('clock_out')
                                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()]);
                        }])
                        ->get();

                    $weekEstimated = 0;
                    $weekTracked = 0;
                    $weekValidCount = 0;

                    foreach ($weekTasks as $task) {
                        $estimatedHours = $task->estimated_time / (1000 * 60 * 60);
                        
                        // Calculate tracked hours with fallback
                        $trackedHours = $task->timeEntries
                            ->where('total_hours', '>', 0)
                            ->sum('total_hours') ?? 0;
                        
                        // If trackedHours is still 0, try calculating from clock_in/clock_out
                        if ($trackedHours == 0 && $task->timeEntries->count() > 0) {
                            $trackedHours = $task->timeEntries->sum(function ($entry) {
                                if (!$entry->clock_in || !$entry->clock_out) {
                                    return $entry->total_hours ?? 0;
                                }
                                $clockIn = \Carbon\Carbon::parse($entry->clock_in);
                                $clockOut = \Carbon\Carbon::parse($entry->clock_out);
                                $minutes = $clockOut->diffInMinutes($clockIn);
                                
                                if ($entry->break_start && $entry->break_end) {
                                    $breakStart = \Carbon\Carbon::parse($entry->break_start);
                                    $breakEnd = \Carbon\Carbon::parse($entry->break_end);
                                    $minutes -= $breakStart->diffInMinutes($breakEnd);
                                }
                                
                                if ($entry->lunch_start && $entry->lunch_end) {
                                    $lunchStart = \Carbon\Carbon::parse($entry->lunch_start);
                                    $lunchEnd = \Carbon\Carbon::parse($entry->lunch_end);
                                    $minutes -= $lunchStart->diffInMinutes($lunchEnd);
                                }
                                
                                return max(0, round($minutes / 60, 2));
                            });
                        }
                        
                        if ($trackedHours > 0) {
                            $weekEstimated += $estimatedHours;
                            $weekTracked += $trackedHours;
                            $weekValidCount++;
                        }
                    }

                    $weekEfficiency = $weekTracked > 0 && $weekValidCount > 0 
                        ? ($weekEstimated / $weekTracked) * 100 
                        : null;

                    $trendData[] = [
                        'week' => $weekStart->format('M d'),
                        'efficiency' => $weekEfficiency !== null ? round($weekEfficiency, 2) : null,
                    ];
                }
            }

            return response()->json([
                'summary' => [
                    'average_efficiency' => round($averageEfficiency, 2),
                    'total_estimated_time' => round($totalEstimatedHours, 2),
                    'total_tracked_time' => round($totalTrackedHours, 2),
                    'overrun_rate' => round($overrunRate, 2),
                ],
                'tasks' => $taskData,
                'user_efficiency' => $userEfficiency,
                'trend' => $trendData,
            ]);
        } catch (\Exception $e) {
            \Log::error('Efficiency analytics error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Failed to load efficiency data',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
