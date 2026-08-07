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
        // subscriptions: el plan que un usuario efectivamente contrató. Si un
        // usuario no tiene ninguna fila activa acá, se asume que está en el
        // plan Gratis correspondiente (sección 7: "el cliente no paga nada por
        // el uso básico de la plataforma") — no hace falta crear una fila para
        // cada usuario nuevo.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();

            // "grace" = venció pero todavía está en el período de gracia
            // (sección 9.6) antes de degradar automáticamente a Gratis.
            $table->string('status')->default('active');

            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();

            // Límites a medida para cuentas institucionales (sección 7.2 y 7.4:
            // "sus límites de flota se definen a la medida"). Si están vacíos,
            // se usan los límites de la fila del plan tal cual.
            $table->unsignedInteger('custom_max_clients')->nullable();
            $table->unsignedInteger('custom_max_fleets')->nullable();
            $table->unsignedInteger('custom_max_drivers_per_fleet')->nullable();

            // Quién activó esta suscripción (pago por transferencia con
            // activación manual, sección 7.5) — para la auditoría de la
            // sección 9.6, junto con subscription_changes.
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
