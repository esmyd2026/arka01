<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_activity_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('source', 30)->default('gps');
            $table->timestamps();
            $table->index(['driver_user_id', 'started_at']);
            $table->index(['driver_user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_activity_sessions');
    }
};
