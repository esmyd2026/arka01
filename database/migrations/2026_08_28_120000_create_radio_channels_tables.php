<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radio_channels', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('owner_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name', 60);
            // Código de capacidad aleatorio y revocable. Nunca se comparte el
            // ID incremental del canal ni del usuario propietario.
            $table->string('share_code', 64)->unique();
            $table->timestamps();
        });

        Schema::create('radio_channel_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radio_channel_id')->constrained('radio_channels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->unique(['radio_channel_id', 'user_id']);
            $table->index(['user_id', 'radio_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radio_channel_members');
        Schema::dropIfExists('radio_channels');
    }
};
