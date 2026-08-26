<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gap real encontrado depurando un test (no pedido explícitamente,
     * pero necesario): varias notificaciones de la app (`Ride*PushNotification`,
     * `CooperativeRideAssignedPushNotification`, etc.) declaran el canal
     * 'database' además de WebPush — ese canal necesita esta tabla estándar
     * de Laravel, que nunca se había migrado en este proyecto. Sin ella,
     * cualquier notify() con ese canal revienta con un error de SQL en vez
     * de guardar el aviso.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
