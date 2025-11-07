<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Session\TokenMismatchException;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Temporarily exclude login to test - REMOVE AFTER TESTING
        'login',
    ];

    /**
     * Determine if the session and input CSRF tokens match.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function tokensMatch($request)
    {
        // Get the token from various sources
        $token = $request->input('_token') 
            ?: $request->header('X-CSRF-TOKEN')
            ?: $request->header('X-XSRF-TOKEN')
            ?: $request->cookie('XSRF-TOKEN');

        // If still no token, try to get it from the session
        if (!$token && $request->hasSession()) {
            $token = $request->session()->token();
        }

        // Compare with session token
        if (!$request->hasSession() || !is_string($request->session()->token())) {
            return false;
        }

        return is_string($token) &&
               hash_equals($request->session()->token(), $token);
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, \Closure $next)
    {
        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException $e) {
            // Log the error for debugging
            \Log::warning('CSRF token mismatch', [
                'url' => $request->url(),
                'method' => $request->method(),
                'has_session' => $request->hasSession(),
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
                'token_from_request' => $request->input('_token') ?: $request->header('X-CSRF-TOKEN') ?: $request->header('X-XSRF-TOKEN'),
                'token_from_cookie' => $request->cookie('XSRF-TOKEN'),
                'session_token' => $request->hasSession() ? $request->session()->token() : null,
            ]);

            // For Inertia requests, return a JSON response instead of redirecting
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                return response()->json([
                    'message' => 'CSRF token mismatch. Please refresh the page and try again.',
                    'error' => 'CSRF_TOKEN_MISMATCH'
                ], 419);
            }

            // For regular requests, let the parent handle it
            throw $e;
        }
    }
}

