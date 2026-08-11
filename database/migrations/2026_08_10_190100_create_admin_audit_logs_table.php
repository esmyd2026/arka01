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
        // Auditoría de acciones administrativas críticas (pedido explícito
        // del usuario, roadmap de mejoras sección 18) — arranca con los
        // cambios de configuración de WhatsApp (el primer módulo que lo
        // necesita); reutilizable para lo que se sume después (documentos,
        // suscripciones, etc.) sin otra tabla. `old_value`/`new_value` NUNCA
        // llevan un secreto en texto plano (ver AdminAuditLogger::log()) —
        // para un campo sensible se guarda solo si cambió o no, nunca el
        // valor. Es un registro inmutable: sin updated_at, no se edita.
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action');
            $table->string('module');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->timestamp('created_at');

            $table->index(['module', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
