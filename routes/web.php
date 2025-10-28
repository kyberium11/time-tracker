<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

    Route::get('/admin/clickup/logs', function () {
        return Inertia::render('ClickUpLogs');
    })->name('clickup.logs');
    
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
