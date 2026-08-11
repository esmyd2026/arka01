<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los "vocablos/expresiones asociadas" del pedido explícito del usuario
     * — cada frase o palabra que, si aparece en el mensaje normalizado
     * (App\Services\Chatbot\MessageNormalizer), suma puntos a favor de esa
     * intención. `weight` deja marcar frases más específicas como señal más
     * fuerte que una palabra suelta genérica.
     *
     * Vocabulario inicial sembrado acá mismo (mismo criterio que
     * `chatbot_intents`) — ya normalizado (minúsculas, sin tildes), para
     * quedar listo para usar sin depender de un seeder aparte.
     */
    public function up(): void
    {
        Schema::create('chatbot_intent_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chatbot_intent_id')->constrained()->cascadeOnDelete();
            // Se guarda ya normalizada (minúsculas, sin tildes) — ver
            // App\Models\ChatbotIntentKeyword::booted().
            $table->string('phrase');
            $table->unsignedTinyInteger('weight')->default(1);
            $table->timestamps();

            $table->index(['chatbot_intent_id', 'phrase'], 'chatbot_intent_keywords_intent_phrase_index');
        });

        $now = now();

        // code => [[phrase, weight], ...]
        $keywords = [
            'SALUDO' => [
                ['hola', 2], ['buenas', 2], ['buenos dias', 3], ['buenas tardes', 3], ['buenas noches', 3],
                ['hey', 1], ['que tal', 2], ['saludos', 2],
            ],
            'REGISTRO' => [
                ['crear cuenta', 3], ['crear una cuenta', 3], ['registrarme', 2], ['registro', 1],
                ['abrir cuenta', 2], ['quiero registrarme', 3], ['como me registro', 3], ['hacer una cuenta', 2],
                ['no tengo cuenta', 2], ['darme de alta', 2],
            ],
            'INICIAR_SESION' => [
                ['no puedo entrar', 3], ['no puedo iniciar sesion', 3], ['no me deja entrar', 3],
                ['problemas para ingresar', 3], ['no puedo acceder', 2], ['no entro a la app', 3],
                ['error al iniciar sesion', 3], ['no puedo loguearme', 2], ['no me deja acceder', 2],
            ],
            'CODIGO_NO_RECIBIDO' => [
                ['no me llego el codigo', 3], ['no recibi el codigo', 3], ['no llega el codigo', 3],
                ['reenviar codigo', 2], ['no me llega el codigo de verificacion', 3], ['codigo no llega', 2],
                ['no me llego el sms', 2], ['no me llego nada', 1],
            ],
            'RECUPERAR_ACCESO' => [
                ['olvide mi contrasena', 3], ['recuperar contrasena', 3], ['no recuerdo mi clave', 3],
                ['cambiar contrasena', 2], ['perdi mi clave', 2], ['olvide la clave', 2],
            ],
            'INFORMACION_CLIENTE' => [
                ['soy cliente', 2], ['quiero ser cliente', 3], ['como pido una carrera', 3],
                ['quiero pedir un viaje', 2], ['informacion de cliente', 2], ['como funciona para clientes', 3],
                ['quiero pedir una carrera', 3],
            ],
            'INFORMACION_CONDUCTOR' => [
                ['quiero ser conductor', 3], ['como ser conductor', 3], ['quiero manejar', 2],
                ['registrarme como conductor', 3], ['quiero trabajar como conductor', 3], ['informacion de conductor', 2],
                ['quiero trabajar con mi carro', 3], ['quiero trabajar con mi vehiculo', 3], ['ser chofer', 2],
            ],
            'DOCUMENTOS_CONDUCTOR' => [
                ['que documentos necesito', 3], ['documentos del conductor', 3], ['que papeles necesito', 2],
                ['documentos para ser conductor', 3], ['que necesito para ser conductor', 3], ['licencia', 1],
            ],
            'SUSCRIPCIONES' => [
                ['mi suscripcion', 2], ['mi plan', 1], ['como funciona mi plan', 3], ['cuanto cuesta el plan', 3],
                ['planes de arka01', 2], ['precios de los planes', 2], ['mejorar mi plan', 2],
            ],
            'COMO_FUNCIONA_ARKA01' => [
                ['como funciona arka01', 3], ['que es arka01', 3], ['que hace arka01', 2],
                ['como funciona la app', 3], ['que es esta app', 2], ['como funciona esto', 2],
            ],
            'SOPORTE' => [
                ['hablar con soporte', 3], ['necesito soporte', 2], ['quiero un asesor', 2],
                ['hablar con alguien', 2], ['necesito ayuda urgente', 2], ['no me solucionaste', 2],
                ['quiero hablar con una persona', 3], ['atencion al cliente', 2], ['quiero un humano', 2],
            ],
            'PREGUNTA_FRECUENTE' => [
                ['preguntas frecuentes', 3], ['tengo una pregunta', 2], ['tengo una duda', 2],
                ['quiero preguntar algo', 2], ['faq', 1],
            ],
            'DESPEDIDA' => [
                ['gracias', 2], ['muchas gracias', 2], ['chau', 2], ['adios', 2], ['nos vemos', 2],
                ['hasta luego', 2], ['listo gracias', 2], ['eso es todo', 2],
            ],
        ];

        $intentIds = DB::table('chatbot_intents')->pluck('id', 'code');

        foreach ($keywords as $code => $phrases) {
            $intentId = $intentIds[$code] ?? null;
            if (! $intentId) {
                continue;
            }

            foreach ($phrases as [$phrase, $weight]) {
                DB::table('chatbot_intent_keywords')->insert([
                    'chatbot_intent_id' => $intentId,
                    'phrase' => $phrase,
                    'weight' => $weight,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_intent_keywords');
    }
};
