<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clickup_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event')->nullable();
            $table->string('task_id')->nullable();
            $table->integer('status_code')->nullable();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clickup_webhook_logs');
    }
};


