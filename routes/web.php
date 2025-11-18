<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Diagnostic route for CSRF debugging
Route::get('/debug-csrf', function () {
    return response()->json([
        'has_session' => request()->hasSession(),
        'session_id' => request()->hasSession() ? request()->session()->getId() : null,
        'session_token' => request()->hasSession() ? request()->session()->token() : null,
        'csrf_token' => csrf_token(),
        'xsrf_cookie' => request()->cookie('XSRF-TOKEN'),
        'session_cookie' => request()->cookie(config('session.cookie')),
        'session_driver' => config('session.driver'),
        'session_secure' => config('session.secure'),
        'session_same_site' => config('session.same_site'),
        'app_url' => config('app.url'),
        'all_cookies' => request()->cookies->all(),
    ]);
});

// Redirect root to login
Route::get('/', function () {
    return redirect('/login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/users', function () {
        return Inertia::render('Users');
    })->name('users.index');
    
    Route::get('/teams', function () {
        return Inertia::render('Teams');
    })->name('teams.index');
    
    Route::get('/analytics', function () {
        return Inertia::render('Analytics');
    })->name('analytics.index');
    
    Route::get('/efficiency', function () {
        return Inertia::render('EfficiencyAnalytics');
    })
        ->middleware(['role:admin'])
        ->name('efficiency.index');

    Route::get('/admin/clickup/logs', function () {
        return Inertia::render('ClickUpLogs');
    })->name('clickup.logs');
    
    Route::get('/deploy', [App\Http\Controllers\DeployController::class, 'index'])
        ->middleware(['role:developer'])
        ->name('deploy.index');
    
    Route::get('/time-entries', function () {
        return Inertia::render('TimeEntryManagement');
    })
        ->middleware(['role:developer'])
        ->name('time-entries.index');
    
    // Impersonation routes (admin and developer only)
    Route::middleware(['role:admin,developer'])->group(function () {
    Route::get('/impersonate', [App\Http\Controllers\ImpersonationController::class, 'index'])
        ->middleware('role:admin,developer')
        ->name('impersonate.index');
    });
    
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
