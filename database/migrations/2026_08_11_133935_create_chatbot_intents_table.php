<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo administrable de intenciones del chatbot (pedido explícito
     * del usuario, sección "Administración → Chatbot"): SALUDO,
     * CODIGO_NO_RECIBIDO, INFORMACION_CONDUCTOR, etc. — cada una con sus
     * vocablos asociados en `chatbot_intent_keywords`. `DESCONOCIDO` no es
     * una fila acá: es la clasificación por defecto del motor
     * (App\Services\Chatbot\IntentDetector) cuando nada supera el umbral de
     * confianza.
     *
     * El catálogo inicial se siembra acá mismo (mismo criterio que
     * `faqs`/`rating_reasons`: existe siempre, incluso en una base nueva sin
     * seeders aparte), basado en las funcionalidades reales de Arka01 ya
     * construidas (roles cliente/conductor, flota, documentos de conductor,
     * planes, WhatsApp) — no en el menú de referencia genérico del pedido.
     */
    public function up(): void
    {
        Schema::create('chatbot_intents', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            // Mismo criterio que Faq::audience: "ambos" siempre aplica,
            // más lo propio del rol de quien escribe (si ya se sabe).
            $table->string('role_scope')->default('ambos');
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_menu')->default(true);
            $table->string('menu_label')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            // Texto fijo de respuesta — null cuando la respuesta la arma la
            // acción (ver $action) en vez de un texto siempre igual.
            $table->text('reply_message')->nullable();
            // Qué acción segura del catálogo fijo dispara esta intención
            // (pedido explícito: "utiliza de forma segura el servicio
            // existente" — un admin elige ENTRE estas, nunca escribe código):
            // show_menu | resend_code | escalate_support | answer_faq | null
            // (null = solo el texto de reply_message, sin efecto adicional).
            $table->string('action')->nullable();
            $table->timestamps();
        });

        $now = now();

        // [code, label, role_scope, show_in_menu, menu_label, sort_order, reply_message, action]
        $intents = [
            ['SALUDO', 'Saludo', 'ambos', false, null, 0, null, 'show_menu'],
            ['REGISTRO', 'Crear una cuenta', 'ambos', true, '📝 Crear una cuenta', 10,
                "Crear una cuenta es rápido: elegí si vas a ser cliente o conductor, tu nombre, correo, teléfono y contraseña — te vamos a pedir verificar tu teléfono por acá mismo antes de terminar.\n\nEntrá a arka01.com y tocá \"Crear cuenta\".", null],
            ['INICIAR_SESION', 'Problemas para ingresar', 'ambos', true, '🔐 Problemas para ingresar', 20,
                "Podés entrar con tu teléfono, correo o usuario, más tu contraseña. Si no te deja:\n\n1. Revisá que no tengas mayúsculas/espacios de más.\n2. Si olvidaste la contraseña, usá \"¿Olvidó su contraseña?\" en la pantalla de inicio de sesión.\n3. Si ya iniciaste sesión en otro dispositivo, la app te va a guiar para cerrar esa sesión y entrar en el nuevo.\n\n¿Alguna de estas es tu caso?", null],
            ['CODIGO_NO_RECIBIDO', 'No recibí mi código', 'ambos', true, '🔢 No recibí mi código', 30, null, 'resend_code'],
            ['RECUPERAR_ACCESO', 'Olvidé mi contraseña', 'ambos', false, null, 40,
                'Para eso usá el link "¿Olvidó su contraseña?" en la pantalla de inicio de sesión de Arka01 — te mandamos un correo para elegir una nueva.', null],
            ['INFORMACION_CLIENTE', 'Soy cliente', 'ambos', true, '👤 Soy cliente', 50,
                "Como cliente armás tu \"flota de confianza\" de conductores e invitás a los que ya conocés, o pedís una carrera al directorio público. El precio se calcula por distancia × la tarifa de cada conductor, siempre visible antes de confirmar.\n\n¿Ya tenés cuenta o querés crear una?", null],
            ['INFORMACION_CONDUCTOR', 'Quiero ser conductor', 'ambos', true, '🚗 Quiero ser conductor', 60,
                "¡Perfecto! Como conductor recibís solicitudes de carrera de tus clientes de confianza y del directorio público, con tu propia tarifa por km. Hace falta activar tu perfil de conductor con tus datos de vehículo y subir tu licencia — un admin lo revisa antes de que aparezcas verificado.\n\nEscribime \"documentos\" si querés el detalle de qué necesitás subir, o creá tu cuenta desde arka01.com.", null],
            ['DOCUMENTOS_CONDUCTOR', 'Documentos de conductor', 'conductor', false, null, 70,
                'Necesitás: foto de tu licencia de conducir y foto de tu vehículo, desde "Mi perfil de conductor" en la app. Un admin las revisa manualmente antes de que tu cuenta quede verificada — mientras tanto podés seguir recibiendo carreras con normalidad.', null],
            ['SUSCRIPCIONES', 'Suscripciones y planes', 'ambos', false, null, 80,
                'Tanto clientes como conductores tienen un plan Gratis por defecto, con planes pagos que suman más flotas/clientes de confianza, directorio público y otros beneficios según el rol. Podés ver y comparar los planes desde "Mi plan" dentro de la app.', null],
            ['COMO_FUNCIONA_ARKA01', 'Cómo funciona Arka01', 'ambos', false, null, 90,
                'Arka01 conecta clientes con conductores de confianza en Ecuador: armás tu propia flota de conductores conocidos, o pedís al directorio público si ninguno está disponible. El precio siempre se calcula por distancia y se ve antes de confirmar, sin negociar aparte.', null],
            ['SOPORTE', 'Hablar con soporte', 'ambos', true, '💬 Hablar con soporte', 100, null, 'escalate_support'],
            ['PREGUNTA_FRECUENTE', 'Preguntas frecuentes', 'ambos', true, '❓ Preguntas frecuentes', 110, null, 'answer_faq'],
            ['DESPEDIDA', 'Despedida', 'ambos', false, null, 120, null, null],
        ];

        foreach ($intents as [$code, $label, $roleScope, $showInMenu, $menuLabel, $sortOrder, $replyMessage, $action]) {
            DB::table('chatbot_intents')->insert([
                'code' => $code,
                'label' => $label,
                'role_scope' => $roleScope,
                'is_active' => true,
                'show_in_menu' => $showInMenu,
                'menu_label' => $menuLabel,
                'sort_order' => $sortOrder,
                'reply_message' => $replyMessage,
                'action' => $action,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_intents');
    }
};
