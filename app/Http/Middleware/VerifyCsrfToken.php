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
        'debug-csrf', // Diagnostic route
    ];

    /**
     * Determine if the session and input CSRF tokens match.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function tokensMatch($request)
    {
        // Ensure session is started
        if (!$request->hasSession()) {
            \Log::warning('CSRF: No session available', [
                'url' => $request->url(),
                'method' => $request->method(),
            ]);
            return false;
        }

        // Get the token from various sources
        $token = $request->input('_token') 
            ?: $request->header('X-CSRF-TOKEN')
            ?: $request->header('X-XSRF-TOKEN')
            ?: $request->cookie('XSRF-TOKEN');

        // Get session token
        $sessionToken = $request->session()->token();

        // If no token from request, use session token (for first request)
        if (!$token) {
            $token = $sessionToken;
        }

        // Compare tokens
        if (!is_string($token) || !is_string($sessionToken)) {
            \Log::warning('CSRF: Invalid token format', [
                'token_type' => gettype($token),
                'session_token_type' => gettype($sessionToken),
            ]);
            return false;
        }

        $matches = hash_equals($sessionToken, $token);
        
        if (!$matches) {
            \Log::warning('CSRF: Token mismatch', [
                'token_length' => strlen($token),
                'session_token_length' => strlen($sessionToken),
                'token_preview' => substr($token, 0, 10) . '...',
                'session_token_preview' => substr($sessionToken, 0, 10) . '...',
            ]);
        }

        return $matches;
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

