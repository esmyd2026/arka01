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
        // Monitoreo de errores/eventos críticos (pedido explícito del
        // usuario, roadmap de mejoras sección 9): "poder detectar problemas
        // importantes sin revisar directamente logs del servidor". Se llena
        // desde App\Services\SystemEventLogger, en los puntos donde ya se
        // sabía que algo falló (envío de WhatsApp, SOS, excepciones no
        // atrapadas) — nunca se guarda un token/contraseña en `context` ni
        // en `message` (ver SystemEventLogger::log()).
        Schema::create('system_events', function (Blueprint $table) {
            $table->id();
            // info | warning | error | critical
            $table->string('severity')->default('error');
            // whatsapp | push | ride | pricing | documents | integration | backend | sos
            $table->string('module');
            $table->string('event_type');
            $table->string('channel')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // failed | resolved | ignored — triage simple, sin flujo de estados propio.
            $table->string('status')->default('failed');
            $table->text('message');
            $table->string('provider_error_code')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('last_attempt_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['module', 'severity']);
            $table->index('event_type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_events');
    }
};
