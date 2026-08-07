<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un cliente distinto al dueño del Expreso, que pidió sumarse a esa misma
     * ruta porque su origen/destino/horario le calzan cerca (pedido explícito
     * del usuario: "que se unan otras personas en su ruta"). El dueño del
     * Expreso aprueba o rechaza cada pedido — mismo espíritu de "círculo de
     * confianza" que ya rige el resto de la app (flotas, invitaciones).
     */
    public function up(): void
    {
        Schema::create('express_route_companions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('express_route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passenger_user_id')->constrained('users')->cascadeOnDelete();

            // Su propio punto de recogida/destino, por si difiere un poco del
            // origen/destino exacto del dueño — nullable: si no lo manda, se
            // asume el mismo del Expreso.
            $table->decimal('origin_lat', 10, 7)->nullable();
            $table->decimal('origin_lng', 10, 7)->nullable();
            $table->string('origin_address')->nullable();
            $table->decimal('destination_lat', 10, 7)->nullable();
            $table->decimal('destination_lng', 10, 7)->nullable();
            $table->string('destination_address')->nullable();

            $table->string('status')->default('pending'); // pending | accepted | rejected | left
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('express_route_companions');
    }
};
