<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserActivityLog;
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
            } elseif ($user->role === 'admin') {
                // Admins see all data
                $users = User::where('role', 'employee')->get();
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

            $totalHours = $entries->sum('total_hours');
            $avgHours = $users->count() > 0 ? round($totalHours / $users->count(), 2) : 0;
            
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
                    'total_hours' => round($totalHours, 2),
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
     * Get user-specific analytics.
     */
    public function userAnalytics(Request $request, User $user)
    {
        $startDate = $request->input('start_date', now()->startOfMonth());
        $endDate = $request->input('end_date', now()->endOfMonth());

        $entries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('clock_out')
            ->orderBy('date', 'desc')
            ->get();

        $totalHours = $entries->sum('total_hours');

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
     * Get all individual entries with filters.
     */
    public function individualEntries(Request $request)
    {
        $user = Auth::user()->load('managedTeam');
        $query = TimeEntry::with(['user', 'user.team'])
            ->whereNotNull('clock_out');

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
                ->where('role', 'employee')
                ->pluck('id')
                ->toArray();
            $query->whereIn('user_id', $userIds);
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
            $entries = $query->get();
            $filtered = $entries->filter(function($entry) use ($request) {
                if (!$entry->user->shift_start || !$entry->user->shift_end) {
                    return false;
                }
                
                $shiftStart = strtotime($entry->user->shift_start);
                $shiftEnd = strtotime($entry->user->shift_end);
                $clockIn = strtotime($entry->clock_in);
                $shiftHours = ($shiftEnd - $shiftStart) / 3600;
                
                switch ($request->status) {
                    case 'late':
                        return $clockIn > $shiftStart + 300; // 5 minutes late
                    case 'undertime':
                        return $entry->total_hours < $shiftHours;
                    case 'overtime':
                        return $entry->total_hours > $shiftHours;
                    case 'perfect':
                        return $clockIn <= $shiftStart + 300 && $entry->total_hours >= $shiftHours - 0.5;
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
            
            $users = User::where('role', 'employee')
                ->where('team_id', $user->managedTeam->id)
                ->get(['id', 'name', 'email']);
        } else {
            $users = User::where('role', 'employee')->get(['id', 'name', 'email']);
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
        ->where('role', 'employee');

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
}
