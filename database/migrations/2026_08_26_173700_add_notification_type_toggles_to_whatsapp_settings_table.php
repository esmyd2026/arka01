<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            // Pedido explícito del usuario: "que yo las active o desactive y
            // si las desactivo entonce esas notificaciones no llegaran" —
            // distinto de `ride_notifications_enabled` (el apagado general
            // de TODO, ya existía) — acá es por tipo puntual, para poder
            // apagar solo la que salga más cara sin perder el resto. Todas
            // arrancan en true: nadie deja de recibir nada de golpe con esta
            // migración, es una opción para recortar si hace falta.
            $table->boolean('notify_ride_accepted')->default(true)->after('client_ride_booking_enabled');
            $table->boolean('notify_ride_started')->default(true)->after('notify_ride_accepted');
            $table->boolean('notify_ride_arrived')->default(true)->after('notify_ride_started');
            $table->boolean('notify_ride_picked_up')->default(true)->after('notify_ride_arrived');
            $table->boolean('notify_ride_completed')->default(true)->after('notify_ride_picked_up');
            $table->boolean('notify_new_ride_alert')->default(true)->after('notify_ride_completed');
            $table->boolean('notify_cooperative_invitation')->default(true)->after('notify_new_ride_alert');
            $table->boolean('notify_scheduled_reminder')->default(true)->after('notify_cooperative_invitation');
            $table->boolean('notify_offer_expired')->default(true)->after('notify_scheduled_reminder');
            $table->boolean('notify_driver_disconnected')->default(true)->after('notify_offer_expired');

            // Pedido explícito del usuario ("coloquemos precios estimados
            // por las cantidades de mensajes enviados") — editable a mano
            // porque el precio real de Meta varía por categoría/país y
            // cambia con el tiempo; $0.0012 es el dato que dio el usuario
            // como referencia inicial, no un valor oficial fijo.
            $table->decimal('estimated_cost_per_message', 8, 4)->default(0.0012)->after('notify_driver_disconnected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn([
                'notify_ride_accepted', 'notify_ride_started', 'notify_ride_arrived',
                'notify_ride_picked_up', 'notify_ride_completed', 'notify_new_ride_alert',
                'notify_cooperative_invitation', 'notify_scheduled_reminder',
                'notify_offer_expired', 'notify_driver_disconnected',
                'estimated_cost_per_message',
            ]);
        });
    }
};
