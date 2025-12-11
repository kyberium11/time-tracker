<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SessionClickLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionLogController extends Controller
{
    /**
     * List session click logs with filters.
     * Developer-only via route middleware.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = $validated['per_page'] ?? 50;

        $logsQuery = SessionClickLog::with('user')
            ->orderByDesc('created_at');

        if (!empty($validated['user_id'])) {
            $logsQuery->where('user_id', $validated['user_id']);
        }

        if (!empty($validated['start_date'])) {
            $logsQuery->whereDate('created_at', '>=', $validated['start_date']);
        }

        if (!empty($validated['end_date'])) {
            $logsQuery->whereDate('created_at', '<=', $validated['end_date']);
        }

        $logs = $logsQuery->paginate($perPage);

        // Provide user options for filtering (excluding system user #1)
        $users = User::where('id', '!=', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
            'filters' => [
                'users' => $users,
            ],
        ]);
    }

    /**
     * Store a click-only activity log.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'path' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Avoid logging system user
        if (Auth::id() === 1) {
            return response()->json(['status' => 'skipped'], 200);
        }

        // Trim metadata size to prevent oversized payloads
        $metadata = $validated['metadata'] ?? [];
        $validated['metadata'] = $metadata ? array_slice($metadata, 0, 20) : null;

        SessionClickLog::create([
            'user_id' => Auth::id(),
            'description' => $validated['description'] ?? null,
            'path' => $validated['path'] ?? request()->path(),
            'metadata' => $validated['metadata'],
        ]);

        return response()->json(['status' => 'ok']);
    }
}

