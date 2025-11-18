<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use App\Services\ClickUpService;
use App\Models\User;
use App\Models\Task;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClickUpService::class, function () {
            return new ClickUpService(
                config('clickup.api_token'),
                config('clickup.signing_secret')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        // Ensure each user has a built-in Break task
        User::created(function (User $user) {
            Task::firstOrCreate([
                'user_id' => $user->id,
                'title' => 'Break',
            ], [
                'description' => 'Built-in break task',
                'status' => 'active',
                'clickup_task_id' => null,
            ]);
        });
    }
}
