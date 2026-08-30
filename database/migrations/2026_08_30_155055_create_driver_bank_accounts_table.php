<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: el conductor puede declarar varias
     * cuentas bancarias en su perfil (cédula del titular, banco, tipo de
     * cuenta, número de cuenta) y marcar una como favorita — para que el
     * cliente las vea (la favorita primero) cuando la carrera es por
     * transferencia y el conductor va en camino a recogerlo. Ver
     * App\Models\DriverBankAccount.
     */
    public function up(): void
    {
        Schema::create('driver_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('identity_number', 20);
            $table->string('bank_name', 100);
            $table->enum('account_type', ['ahorros', 'corriente']);
            $table->string('account_number', 30);
            // Pedido explícito del usuario: una sola favorita por
            // conductor — se hace cumplir en App\Models\DriverBankAccount,
            // no acá con una constraint (SQLite/MySQL no tienen una forma
            // simple de expresar "único cuando true" sin un índice parcial).
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_bank_accounts');
    }
};
