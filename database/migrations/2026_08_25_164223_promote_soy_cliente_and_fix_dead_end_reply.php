<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario, probando el bot de verdad: "primero el
     * soy cliente debe ir al inicio... soy conductor, soy cliente y mas
     * informacion. fijate que el soy cliente no funciona esta caido... y
     * deberia estar en mas informacion solicitar carrera."
     *
     * INFORMACION_CLIENTE ("Soy cliente") ya existía, pero era solo texto
     * fijo terminando en una pregunta retórica ("¿Ya tenés cuenta o querés
     * crear una?") sin ningún botón real — cualquier respuesta después caía
     * al fallback genérico ("no logré identificar..."), exactamente lo que
     * viste como "caído". Pasa a ser el botón promovido en el lugar de
     * PEDIR_CARRERA (`action: 'client_menu'`, ver ChatbotEngine::process()),
     * con 2 botones de verdad al final. PEDIR_CARRERA dejar de estar
     * promovido — vuelve a aparecer solo dentro de "Más Info" (mismo
     * mecanismo que ya usa REGISTRO desde que se sacó de ahí), y se ofrece
     * también como uno de los 2 botones de "Soy cliente".
     */
    public function up(): void
    {
        DB::table('chatbot_intents')->where('code', 'INFORMACION_CLIENTE')->update([
            'role_scope' => 'cliente',
            'action' => 'client_menu',
            'reply_message' => "Como cliente armás tu \"flota de confianza\" de conductores e invitás a los que ya conocés, o pedís una carrera al directorio público. El precio se calcula por distancia × la tarifa de cada conductor, siempre visible antes de confirmar.\n\n¿Pedimos su carrera, o prefiere crear su cuenta primero?",
            'updated_at' => now(),
        ]);

        DB::table('chatbot_intents')->where('code', 'PEDIR_CARRERA')->update([
            'label' => 'Pedir una carrera',
            'menu_label' => '🚗 Pedir una carrera',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('chatbot_intents')->where('code', 'INFORMACION_CLIENTE')->update([
            'role_scope' => 'ambos',
            'action' => null,
            'reply_message' => "Como cliente armás tu \"flota de confianza\" de conductores e invitás a los que ya conocés, o pedís una carrera al directorio público. El precio se calcula por distancia × la tarifa de cada conductor, siempre visible antes de confirmar.\n\n¿Ya tenés cuenta o querés crear una?",
            'updated_at' => now(),
        ]);

        DB::table('chatbot_intents')->where('code', 'PEDIR_CARRERA')->update([
            'label' => 'Soy Pasajero',
            'menu_label' => '🧍 Soy Pasajero',
            'updated_at' => now(),
        ]);
    }
};
