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
        // El "status" original era un enum de MySQL (pending/accepted/cancelled/
        // expired). Cambiarlo a texto simple ahora, antes de sumarle el estado
        // "negotiating" de esta fase, evita depender de doctrine/dbal (que este
        // proyecto no tiene instalado) para modificar enums más adelante — el
        // valor permitido queda controlado por la app (RideRequestController),
        // no por una restricción rígida de la base de datos.
        //
        // Para poder borrar la columna hay que soltar antes las llaves foráneas
        // y los índices compuestos que la usan (fleet_id/driver_user_id se
        // apoyaban en esos índices compuestos para su propia llave foránea).
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropForeign(['fleet_id']);
            $table->dropForeign(['driver_user_id']);
            $table->dropIndex(['fleet_id', 'status']);
            $table->dropIndex(['driver_user_id', 'status']);
            $table->dropColumn('status');
        });

        Schema::table('ride_requests', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('distance_km');

            // Precio que está sobre la mesa en este momento de la negociación
            // (sección 5): arranca en el precio sugerido y puede cambiar una
            // vez si el conductor hace una contraoferta.
            $table->decimal('current_offered_price', 8, 2)->nullable()->after('status');

            // 0 = todavía nadie contraofertó. 1 = el conductor ya usó su única
            // ronda de contraoferta permitida (sección 5: "limitar a una ronda
            // para no alargar el proceso") — a partir de ahí el cliente solo
            // puede aceptar o rechazar, no volver a contraofertar.
            $table->unsignedTinyInteger('negotiation_round')->default(0)->after('current_offered_price');

            // Quién puso el último número sobre la mesa: 'client' o 'driver'.
            // Le dice a la pantalla de quién es el turno de responder.
            $table->string('last_offer_made_by')->default('client')->after('negotiation_round');

            // En una solicitud "a toda la flota" (driver_user_id nulo), el primer
            // conductor que responde —acepta o contraoferta— "toma" la
            // negociación para que los demás dejen de verla como disponible.
            $table->foreignId('negotiating_driver_user_id')
                ->nullable()
                ->after('last_offer_made_by')
                ->constrained('users')
                ->nullOnDelete();

            // Se recrean la llave foránea y los índices que existían antes de
            // tocar la columna "status".
            $table->foreign('fleet_id')->references('id')->on('fleets')->cascadeOnDelete();
            $table->foreign('driver_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['fleet_id', 'status']);
            $table->index(['driver_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropForeign(['fleet_id']);
            $table->dropForeign(['driver_user_id']);
            $table->dropIndex(['fleet_id', 'status']);
            $table->dropIndex(['driver_user_id', 'status']);
            $table->dropConstrainedForeignId('negotiating_driver_user_id');
            $table->dropColumn(['current_offered_price', 'negotiation_round', 'last_offer_made_by']);
            $table->dropColumn('status');
        });

        Schema::table('ride_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'accepted', 'cancelled', 'expired'])->default('pending');
            $table->foreign('fleet_id')->references('id')->on('fleets')->cascadeOnDelete();
            $table->foreign('driver_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['fleet_id', 'status']);
            $table->index(['driver_user_id', 'status']);
        });
    }
};
