<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Botón de acción" para elegir un plan (consideración agregada al
     * alcance): como no hay pasarela de pago (sección 7.5), esto no cobra
     * nada — el usuario elige el plan, sube el comprobante de la
     * transferencia, y un admin lo revisa y activa la suscripción real
     * (Subscription) a mano, igual que ya hacía, pero ahora con un pedido
     * concreto esperando en vez de tener que buscar al usuario a ciegas.
     */
    public function up(): void
    {
        Schema::create('subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('payment_proof_path')->nullable();

            // awaiting_proof: eligió el plan, todavía no subió el comprobante.
            // pending_review: ya lo subió, esperando que el admin lo revise.
            // approved / rejected: el admin ya lo resolvió.
            $table->enum('status', ['awaiting_proof', 'pending_review', 'approved', 'rejected'])->default('awaiting_proof');

            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_requests');
    }
};
