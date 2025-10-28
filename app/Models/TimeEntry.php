<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    protected $fillable = [
        'user_id',
        'task_id',
        'date',
        'clock_in',
        'clock_out',
        'break_start',
        'break_end',
        'lunch_start',
        'lunch_end',
        'total_hours',
    ];

    protected $casts = [
        'date' => 'date',
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
        'break_start' => 'datetime',
        'break_end' => 'datetime',
        'lunch_start' => 'datetime',
        'lunch_end' => 'datetime',
        'total_hours' => 'decimal:2',
    ];

    /**
     * Get the user that owns the time entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Task associated with this entry.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
