<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;

class ImpersonationController extends Controller
{
    /**
     * Show the impersonation page.
     */
    public function index()
    {
        // Only allow admin and developer
        $user = Auth::user();
        if (!in_array($user->role, ['admin', 'developer'])) {
            abort(403, 'Unauthorized');
        }

        $users = User::where('id', '!=', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return Inertia::render('Impersonate', [
            'users' => $users,
            'isImpersonating' => Session::has('impersonating_user_id'),
            'originalUserId' => Session::get('impersonating_user_id'),
        ]);
    }

    /**
     * Start impersonating a user.
     */
    public function start(Request $request, $userId)
    {
        // Only allow admin and developer
        $currentUser = Auth::user();
        if (!in_array($currentUser->role, ['admin', 'developer'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $targetUser = User::findOrFail($userId);

        // Don't allow impersonating yourself
        if ($targetUser->id === $currentUser->id) {
            return response()->json(['error' => 'Cannot impersonate yourself'], 400);
        }

        // Store original user ID in session
        Session::put('impersonating_user_id', $currentUser->id);
        Session::put('impersonating_original_user', [
            'id' => $currentUser->id,
            'name' => $currentUser->name,
            'email' => $currentUser->email,
            'role' => $currentUser->role,
        ]);

        // Log in as the target user
        Auth::guard('web')->login($targetUser);
        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => "Now impersonating {$targetUser->name}",
            'user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role' => $targetUser->role,
            ],
        ]);
    }

    /**
     * Stop impersonating and return to original user.
     */
    public function stop()
    {
        if (!Session::has('impersonating_user_id')) {
            return response()->json(['error' => 'Not currently impersonating'], 400);
        }

        $originalUserId = Session::get('impersonating_user_id');
        $originalUser = User::findOrFail($originalUserId);

        // Log back in as original user
        Auth::guard('web')->login($originalUser);
        request()->session()->regenerate();

        // Clear impersonation session data
        Session::forget('impersonating_user_id');
        Session::forget('impersonating_original_user');

        return response()->json([
            'success' => true,
            'message' => "Returned to {$originalUser->name}",
            'user' => [
                'id' => $originalUser->id,
                'name' => $originalUser->name,
                'email' => $originalUser->email,
                'role' => $originalUser->role,
            ],
        ]);
    }
}

