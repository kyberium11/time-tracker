<?php

use App\Http\Controllers\Api\TimeEntryController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\ClickUpWebhookController;
use App\Http\Controllers\Api\ClickUpLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware(['auth:sanctum'])->group(function () {
    // Time Entry Routes
    Route::prefix('time-entries')->group(function () {
        Route::get('/current', [TimeEntryController::class, 'getCurrentEntry']);
        Route::post('/clock-in', [TimeEntryController::class, 'clockIn']);
        Route::post('/clock-out', [TimeEntryController::class, 'clockOut']);
        Route::post('/break-start', [TimeEntryController::class, 'startBreak']);
        Route::post('/break-end', [TimeEntryController::class, 'endBreak']);
        Route::post('/lunch-start', [TimeEntryController::class, 'startLunch']);
        Route::post('/lunch-end', [TimeEntryController::class, 'endLunch']);
        Route::get('/my-entries', [TimeEntryController::class, 'myEntries']);
    });

    // My tasks
    Route::get('/my/tasks', [TaskController::class, 'myTasks']);
    Route::get('/my/tasks/break', [TaskController::class, 'myBreakTask']);
    Route::get('/tasks/{id}/sync', [TaskController::class, 'sync']);

    // Task timers
    Route::post('/tasks/start', [TimeEntryController::class, 'startTask']);
    Route::post('/tasks/stop', [TimeEntryController::class, 'stopTask']);
    Route::get('/tasks/today-entries', [TimeEntryController::class, 'todayTaskEntries']);
    Route::post('/tasks/{id}/status', [TaskController::class, 'updateStatus']);

    // Admin: ClickUp webhook logs
    Route::middleware(['role:admin'])->get('/admin/clickup/webhook-logs', [ClickUpLogController::class, 'index']);

    // Analytics read routes (admin and manager can access)
    Route::prefix('admin/analytics')->middleware(['role:admin,manager'])->group(function () {
        Route::get('/overview', [AnalyticsController::class, 'overview']);
        Route::get('/individual-entries', [AnalyticsController::class, 'individualEntries']);
        Route::get('/users', [AnalyticsController::class, 'users']);
        Route::get('/user/{user}', [AnalyticsController::class, 'userAnalytics']);
        Route::get('/activity-logs', [AnalyticsController::class, 'activityLogs']);
    });

    // Admin-only routes (user and team management)
    Route::middleware(['role:admin'])->prefix('admin')->group(function () {
        // User Management
        Route::get('/users', [UserManagementController::class, 'index']);
        Route::post('/users', [UserManagementController::class, 'store']);
        Route::get('/users/{id}', [UserManagementController::class, 'show']);
        Route::put('/users/{id}', [UserManagementController::class, 'update']);
        Route::delete('/users/{id}', [UserManagementController::class, 'destroy']);
        
        // Team Management
        Route::get('/teams', [TeamController::class, 'index']);
        Route::post('/teams', [TeamController::class, 'store']);
        Route::get('/teams/{id}', [TeamController::class, 'show']);
        Route::put('/teams/{id}', [TeamController::class, 'update']);
        Route::delete('/teams/{id}', [TeamController::class, 'destroy']);
        Route::get('/teams/managers/list', [TeamController::class, 'getManagers']);
        
        // Analytics - admin only
        Route::get('/analytics', [AnalyticsController::class, 'index']);
        Route::get('/analytics/export/csv', [AnalyticsController::class, 'exportCsv']);
        Route::get('/analytics/export/pdf', [AnalyticsController::class, 'exportPdf']);
    });
});

// ClickUp webhook endpoint (no auth; secured via signing secret)
Route::post('/integrations/clickup/webhook', [ClickUpWebhookController::class, 'handle']);
