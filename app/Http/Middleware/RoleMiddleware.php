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
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $userRole = $request->user()->role;
        $allowedRoles = array_map('trim', explode(',', $roles));

        // Developer role has admin+ access - allow developer to access admin and manager routes
        if ($userRole === 'developer') {
            // Developers can access admin routes
            if (in_array('admin', $allowedRoles)) {
                return $next($request);
            }
            // Developers can access manager routes
            if (in_array('manager', $allowedRoles)) {
                return $next($request);
            }
        }

        // Log for debugging
        \Log::info('RoleMiddleware check', [
            'user_role' => $userRole,
            'allowed_roles' => $allowedRoles,
            'url' => $request->url(),
            'passed' => in_array($userRole, $allowedRoles)
        ]);

        // Check if user has any of the required roles
        if (!in_array($userRole, $allowedRoles)) {
            return response()->json([
                'message' => 'Unauthorized. Required role: ' . $roles,
                'user_role' => $userRole,
                'allowed_roles' => $allowedRoles
            ], 403);
        }

        return $next($request);
    }
}
