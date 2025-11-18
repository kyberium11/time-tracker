<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'clickup_user_id',
        'password',
        'role',
        'team_id',
        'shift_start',
        'shift_end',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the time entries for the user.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get the activity logs for the user.
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(UserActivityLog::class);
    }

    /**
     * Get the team that the user belongs to.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the custom shift schedules for the user.
     */
    public function shiftSchedules(): HasMany
    {
        return $this->hasMany(UserShiftSchedule::class);
    }

    /**
     * Get the team that the user manages.
     */
    public function managedTeam()
    {
        return $this->hasOne(Team::class, 'manager_id');
    }

    /**
     * Check if user is admin.
     * Developers have admin+ privileges, so they are considered admins.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->role === 'developer';
    }

    /**
     * Check if user is manager.
     */
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * Check if user is employee.
     */
    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    /**
     * Check if user is developer.
     */
    public function isDeveloper(): bool
    {
        return $this->role === 'developer';
    }

    /**
     * Resolve the shift start/end for a specific date.
     */
    public function getShiftForDate(\DateTimeInterface|string|null $date = null): ?array
    {
        $date = $date ? \Carbon\Carbon::parse($date) : now();
        $day = $date->dayOfWeek;

        $schedules = $this->relationLoaded('shiftSchedules')
            ? $this->shiftSchedules
            : $this->shiftSchedules()->get();

        /** @var Collection $schedules */
        $schedule = $schedules->firstWhere('day_of_week', $day);

        if ($schedule) {
            return [
                'start' => $schedule->start_time,
                'end' => $schedule->end_time,
            ];
        }

        if ($this->shift_start && $this->shift_end) {
            return [
                'start' => $this->shift_start,
                'end' => $this->shift_end,
            ];
        }

        return null;
    }
}
