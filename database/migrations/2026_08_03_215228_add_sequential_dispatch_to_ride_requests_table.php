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
        // Despacho secuencial estilo Uber (pedido explícito del usuario): en
        // vez de mandarle "a toda la flota" al mismo tiempo, se le ofrece a
        // UN conductor a la vez (el más cercano primero, dentro de la
        // "bolsa" que el cliente eligió: mi flota / público / ambos) con 30
        // segundos para responder — si no responde, pasa al siguiente.
        // `driver_user_id` (ya existente) pasa a significar "a quién le toca
        // responder ahora mismo" en los dos casos (dirigida a propósito, o el
        // candidato actual de una tanda) — así toda la autorización de
        // accept()/counter()/reject() que ya existe sigue funcionando igual,
        // sin tocarla.
        Schema::table('ride_requests', function (Blueprint $table) {
            // Null = solicitud dirigida a un conductor puntual (comportamiento
            // de siempre, sin cascada ni vencimiento). No-null = de qué bolsa
            // salió la tanda de candidatos.
            $table->enum('dispatch_pool', ['fleet', 'public', 'both'])->nullable()->after('driver_user_id');

            // Candidatos que todavía no tuvieron su turno, ordenados por
            // cercanía (el primero de la lista es el próximo si el actual no
            // responde a tiempo).
            $table->json('offer_candidate_ids')->nullable()->after('dispatch_pool');

            // Cuándo se le acaba el tiempo al candidato actual — un Job
            // encolado (App\Jobs\ExpireRideOffer) revisa esto pasados los 30
            // segundos y avanza solo si sigue siendo la oferta vigente.
            $table->timestamp('current_offer_expires_at')->nullable()->after('offer_candidate_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn(['dispatch_pool', 'offer_candidate_ids', 'current_offer_expires_at']);
        });
    }
};
