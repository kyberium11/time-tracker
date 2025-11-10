<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class AssignDeveloperRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:assign-developer {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign developer role to a user by email (hidden from admin views)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("User with email '{$email}' not found.");
            return 1;
        }
        
        $user->role = 'developer';
        $user->save();
        
        $this->info("Successfully assigned developer role to {$user->name} ({$user->email}).");
        $this->warn("Note: Developer role is hidden from admin views and has admin+ access.");
        
        return 0;
    }
}
