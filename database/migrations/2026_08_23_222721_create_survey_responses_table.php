<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encuesta corta de conductor/pasajero (pedido explícito del usuario:
     * entender el problema actual de cada uno y tener indicadores para
     * decidir qué está cubriendo Arka01 y qué no). Sin cuenta obligatoria
     * ("no necesita tener usuario para hacer la encuesta") — `user_id` solo
     * se llena si quien responde ya tenía sesión iniciada en ese momento.
     * Las preguntas están fijas en App\Services\SurveyQuestions, no en una
     * tabla — mismo criterio que RideController::CLIENT_CANCEL_REASONS.
     */
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->string('role'); // pasajero|conductor
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('answers'); // {pregunta_key: opcion_key}
            $table->timestamps();

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
