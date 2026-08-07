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
        // subscription_changes: bitácora de auditoría de la sección 9.6 —
        // "todo cambio de plan (alta, upgrade, downgrade, vencimiento) queda
        // registrado: quién lo activó, cuándo, plan anterior y nuevo". También
        // es el insumo de los indicadores de conversión a planes pagos.
        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Nulo cuando es el primer plan que contrata (no venía de otro).
            $table->foreignId('old_subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('new_subscription_plan_id')->constrained('subscription_plans')->cascadeOnDelete();

            // Quién lo originó: un administrador (activación manual) o el
            // propio sistema (vencimiento automático a Gratis, sección 9.6).
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
    }
};
