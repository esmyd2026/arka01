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
        // express_routes: la Fase 6 (sección 4 del alcance) — un "Expreso" es
        // una ruta fija y recurrente que un cliente publica (horario, días,
        // origen/destino y lo que está dispuesto a pagar); los conductores se
        // postulan y, al aceptar uno, queda un contrato activo que genera la
        // solicitud de carrera automáticamente cada día que corresponda.
        Schema::create('express_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();

            // Etiqueta libre para distinguir rutas cuando el cliente publica
            // más de una (ej. "Turno mañana - Oficina Norte").
            $table->string('name');

            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->string('origin_address')->nullable();
            $table->decimal('destination_lat', 10, 7);
            $table->decimal('destination_lng', 10, 7);
            $table->string('destination_address')->nullable();

            // Días de la semana en que corre (0=domingo..6=sábado, mismo
            // criterio que Carbon::dayOfWeek), guardado como JSON (ej. [1,2,3,4,5]).
            $table->json('days_of_week');
            $table->time('departure_time');

            // Precio por carrera que el cliente ofrece al publicar (sección
            // 4.1); se negocia igual que una carrera normal vía express_applications.
            $table->decimal('offered_price', 8, 2);

            // 'open' = recibiendo postulaciones; 'active' = ya tiene conductor
            // asignado y genera carreras todos los días que corresponda;
            // 'paused' = el cliente la pausó sin cancelar el contrato;
            // 'cancelled' = terminado.
            $table->string('status')->default('open');

            $table->foreignId('assigned_driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'assigned_driver_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('express_routes');
    }
};
