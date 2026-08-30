<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: "esto se convierte en una billetera entre
     * cooperativa y conductor". Una fila por carrera de cooperativa
     * completada (solo cuando la cooperativa configuró sus dos tarifas, ver
     * App\Services\Ride\RideLifecycle::complete()) — el saldo neto entre
     * ambos no se guarda como un campo aparte, se calcula sumando estas
     * filas (App\Models\CooperativeWalletEntry::balanceFor()), igual
     * criterio que App\Models\WhatsAppSession usa para no mantener un
     * "status" que se pueda desincronizar.
     *
     * Ejemplo del usuario: cooperativa cobra $0.50/km, paga $0.30/km (ratio
     * 60% del precio final, no un monto fijo por km). Carrera de $10 en
     * efectivo: el conductor se queda los $10 pero solo le correspondían $6
     * → 'driver_owes_cooperative' por $4. Carrera de $10 por transferencia:
     * la cooperativa se queda los $10 pero le correspondían $6 al conductor
     * → 'cooperative_owes_driver' por $6. Ambos tipos se compensan entre sí
     * al sumar el balance.
     */
    public function up(): void
    {
        Schema::create('cooperative_wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['driver_owes_cooperative', 'cooperative_owes_driver']);
            $table->decimal('amount', 8, 2);
            $table->timestamps();

            $table->index(['cooperative_id', 'driver_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperative_wallet_entries');
    }
};
