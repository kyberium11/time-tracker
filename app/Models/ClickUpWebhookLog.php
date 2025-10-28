<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClickUpWebhookLog extends Model
{
    protected $table = 'clickup_webhook_logs';
    protected $fillable = [
        'event',
        'task_id',
        'status_code',
        'message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}


