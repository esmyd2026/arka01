<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: el menú principal del bot pasa de
     * "Pedir carrera / Crear cuenta / Más opciones" a "Soy Pasajero / Soy
     * Conductor / Más Info" — ver ChatbotEngine::PROMOTED_MENU_CODES.
     *
     * PEDIR_CARRERA conserva su code/flujo (WhatsAppRideBookingHandler no
     * cambia), solo se relabela a "Soy Pasajero". REGISTRO deja de estar
     * promovido a botón propio (SOY_CONDUCTOR cubre ese lugar) pero sigue
     * existiendo dentro de "Más Info" — sigue siendo útil para quien
     * pregunta cómo crear cuenta sin querer pedir una carrera ni conectarse
     * como conductor.
     */
    public function up(): void
    {
        DB::table('chatbot_intents')->where('code', 'PEDIR_CARRERA')->update([
            'label' => 'Soy Pasajero',
            'menu_label' => '🧍 Soy Pasajero',
            'updated_at' => now(),
        ]);

        // Botón nuevo "Soy Conductor" (App\Services\Chatbot\WhatsAppDriverConnectHandler)
        // — el `action: 'driver_menu'` solo sirve de respaldo para un match
        // por texto/etiqueta vía IntentDetector (ver ChatbotEngine::process());
        // el camino normal (tocar el botón) lo resuelve el handler directo,
        // antes de llegar acá, por su propio guard de frases.
        DB::table('chatbot_intents')->insert([
            'code' => 'SOY_CONDUCTOR',
            'label' => 'Soy Conductor',
            'role_scope' => 'conductor',
            'is_active' => true,
            'show_in_menu' => true,
            'menu_label' => '🚕 Soy Conductor',
            'sort_order' => 46,
            'reply_message' => null,
            'action' => 'driver_menu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('chatbot_intents')->where('code', 'PEDIR_CARRERA')->update([
            'label' => 'Pedir una carrera',
            'menu_label' => '🚗 Pedir una carrera',
            'updated_at' => now(),
        ]);

        DB::table('chatbot_intents')->where('code', 'SOY_CONDUCTOR')->delete();
    }
};
