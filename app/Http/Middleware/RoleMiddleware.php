<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $userRole = $request->user()->role;
        // Laravel passes middleware parameters as separate arguments, so:
        // role:admin,manager becomes ['admin', 'manager'] here.
        $allowedRoles = array_map('trim', $roles);

        // Developer role has admin+ access - allow developer to access admin, manager, and developer routes
        if ($userRole === 'developer') {
            // Developers can access admin routes
            if (in_array('admin', $allowedRoles)) {
                return $next($request);
            }
            // Developers can access manager routes
            if (in_array('manager', $allowedRoles)) {
                return $next($request);
            }
            // Developers can access developer routes
            if (in_array('developer', $allowedRoles)) {
                return $next($request);
            }
        }

        $hasRole = in_array($userRole, $allowedRoles);

        // Allow access if the original impersonating user had the required role
        if (!$hasRole && $request->session()->has('impersonating_original_user')) {
            $original = $request->session()->get('impersonating_original_user');
            $hasRole = isset($original['role']) && in_array($original['role'], $allowedRoles);
        }

        // Log for debugging
        \Log::info('RoleMiddleware check', [
            'user_role' => $userRole,
            'allowed_roles' => $allowedRoles,
            'url' => $request->url(),
            'passed' => $hasRole
        ]);

        // Check if user has any of the required roles
        if (!$hasRole) {
            return response()->json([
                'message' => 'Unauthorized. Required role: ' . implode(',', $allowedRoles),
                'user_role' => $userRole,
                'allowed_roles' => $allowedRoles
            ], 403);
        }

        return $next($request);
    }
}
