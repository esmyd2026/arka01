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
        // ride_price_offers: historial de la negociación de precio de una
        // solicitud de carrera (sección 5 — "el precio final acordado queda
        // registrado en la carrera junto con su desglose, visible para
        // ambos"). Es un registro de auditoría append-only: quién ofreció
        // cuánto y cuándo. El precio "vigente" en cada momento vive en
        // ride_requests.current_offered_price; esta tabla es la bitácora.
        Schema::create('ride_price_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_request_id')->constrained()->cascadeOnDelete();

            // Quién puso este monto sobre la mesa (el cliente al pedir la
            // carrera, o el conductor al contraofertar).
            $table->foreignId('offered_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('offered_amount', 8, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ride_price_offers');
    }
};
