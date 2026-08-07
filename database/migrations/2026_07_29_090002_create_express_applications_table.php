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
        // express_applications: postulación de un conductor a una ruta
        // Expreso abierta (sección 4.2). El cliente elige una, o negocia el
        // precio propuesto igual que en una carrera normal (sección 5) — acá
        // se simplifica a una sola contraoferta del conductor (proposed_price),
        // sin rondas múltiples, mismo criterio que ride_requests.
        Schema::create('express_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('express_route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();

            // null = se postula aceptando el precio ofrecido tal cual.
            $table->decimal('proposed_price', 8, 2)->nullable();

            $table->string('status')->default('pending');
            $table->timestamp('applied_at');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->index(['express_route_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('express_applications');
    }
};
