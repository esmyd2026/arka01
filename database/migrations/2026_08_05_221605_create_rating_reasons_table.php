<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de "Motivos de Calificación" (pedido explícito del usuario):
     * cuando alguien baja la calificación de 5 estrellas, tiene que elegir un
     * motivo de esta lista — separado por dirección, porque los motivos por
     * los que un cliente califica mal a un conductor no son los mismos que al
     * revés. Administrable desde /admin/motivos-calificacion (agregar,
     * editar, activar/desactivar), sin que nada quede quemado en código —
     * mismo criterio que subscription_plans: el catálogo inicial se siembra
     * acá mismo, así existe siempre, incluso en una base nueva sin seeders.
     */
    public function up(): void
    {
        Schema::create('rating_reasons', function (Blueprint $table) {
            $table->id();
            $table->enum('direction', ['client_to_driver', 'driver_to_client']);
            $table->string('text', 150);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        // Catálogo provisto textualmente por el usuario: retroalimentación
        // detallada de cada carrera, indicadores de calidad, y forma de
        // identificar conductores/clientes con comportamientos recurrentes.
        $clientToDriver = [
            'Llegó tarde.',
            'No llegó al punto de recogida.',
            'Conducción insegura.',
            'Conducción agresiva.',
            'Exceso de velocidad.',
            'Vehículo sucio.',
            'Vehículo en mal estado.',
            'Mala atención.',
            'Falta de respeto.',
            'No siguió la ruta adecuada.',
            'Cobro incorrecto.',
            'Canceló sin justificación.',
            'Mala comunicación.',
            'No ayudó con el equipaje.',
            'Otro.',
        ];

        $driverToClient = [
            'No se presentó.',
            'Retraso excesivo para abordar.',
            'Canceló al llegar el conductor.',
            'Falta de respeto.',
            'Comportamiento agresivo.',
            'Intentó exceder la capacidad del vehículo.',
            'Intentó transportar objetos no permitidos.',
            'Ensució el vehículo.',
            'Dañó el vehículo.',
            'Cambió repetidamente el destino.',
            'Mala comunicación.',
            'Comportamiento inapropiado.',
            'Otro.',
        ];

        $rows = [];
        foreach ($clientToDriver as $i => $text) {
            $rows[] = ['direction' => 'client_to_driver', 'text' => $text, 'is_active' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ($driverToClient as $i => $text) {
            $rows[] = ['direction' => 'driver_to_client', 'text' => $text, 'is_active' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('rating_reasons')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_reasons');
    }
};
