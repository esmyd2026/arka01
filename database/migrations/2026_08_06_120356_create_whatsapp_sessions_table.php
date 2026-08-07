<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Integración inteligente con WhatsApp (pedido explícito del usuario):
     * cuando un conductor le escribe al número oficial de la plataforma
     * (webhook entrante, ver WhatsAppWebhookController), se abre una
     * "ventana" de 24 horas durante la cual se le puede mandar texto libre
     * (sin plantilla HSM, que tiene costo) — ej. avisos de carrera nueva.
     * Cada fila es UNA apertura de ventana (queda como historial); la
     * vigente es siempre la más reciente de cada usuario.
     */
    public function up(): void
    {
        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sessions');
    }
};
