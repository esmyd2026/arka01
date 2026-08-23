<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Pedir carrera" ya se podía pedir por WhatsApp desde antes
     * (App\Services\Chatbot\WhatsAppRideBookingHandler, con botones de
     * verdad en cada paso — privacidad, cuándo, categoría, cooperativa,
     * confirmar) — pero solo si quien escribía YA sabía escribir una de esas
     * frases exactas ("pedir carrera", "solicitar viaje", etc.). Nunca
     * aparecía como opción numerada en el menú principal del bot, así que
     * nadie que solo saludara ("Hola") se enteraba de que existía. Pedido
     * explícito del usuario: "necesito que por allí se pueda pedir también
     * una carrera pero con botones" — el flujo con botones ya estaba, lo que
     * faltaba era que se pudiera DESCUBRIR desde el menú.
     *
     * `role_scope` = 'cliente': ChatbotIntent::scopeForRole() ya deja pasar
     * "ambos" + el rol propio si se conoce, y NO filtra nada si todavía no
     * se sabe el rol (número sin cuenta) — así que esto aparece para
     * clientes y para números nuevos (que WhatsAppRideBookingHandler ya sabe
     * registrar solos como cliente), pero no para conductores, que no piden
     * carreras.
     */
    public function up(): void
    {
        DB::table('chatbot_intents')->insert([
            'code' => 'PEDIR_CARRERA',
            'label' => 'Pedir una carrera',
            'role_scope' => 'cliente',
            'is_active' => true,
            'show_in_menu' => true,
            'menu_label' => '🚗 Pedir una carrera',
            // Antes de "Hablar con soporte" (100) — pedir una carrera es la
            // acción más buscada, no debería quedar al fondo del menú.
            'sort_order' => 45,
            'reply_message' => null,
            'action' => 'start_ride_booking',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('chatbot_intents')->where('code', 'PEDIR_CARRERA')->delete();
    }
};
