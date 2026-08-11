<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Centro de Ayuda (pedido explícito del usuario, roadmap de mejoras
     * sección 11): preguntas frecuentes distintas según el rol de quien
     * mira — administrable desde /admin/preguntas-frecuentes, mismo criterio
     * que rating_reasons: el catálogo inicial se siembra acá mismo, para que
     * exista siempre, incluso en una base nueva sin seeders aparte.
     */
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            // cliente | conductor | ambos
            $table->string('audience')->default('ambos');
            $table->string('category');
            $table->string('question', 200);
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        $faqs = [
            // Cliente
            ['cliente', 'Carreras', '¿Cómo pido una carrera?', 'Desde el Inicio, toque "Pedir carrera", elija origen y destino, y confirme el conductor o mande la solicitud a toda su flota.', 10],
            ['cliente', 'Carreras', '¿Puedo programar una carrera para más tarde?', 'Sí — al pedir la carrera, active "Programar" y elija la fecha y hora. El conductor la acepta igual, pero recién queda "en curso" cuando arranca de verdad.', 20],
            ['cliente', 'Pagos', '¿Cómo se calcula el precio?', 'Distancia × tarifa que declaró el conductor, con un recargo si es horario nocturno — el desglose se ve siempre antes de confirmar, nunca queda oculto.', 10],
            ['cliente', 'Pagos', '¿Qué formas de pago acepta la app?', 'Depende de cada conductor: efectivo, transferencia, o ambas — se elige al pedir la carrera, entre las que ese conductor acepta.', 20],
            ['cliente', 'Conductores', '¿Cómo armo mi flota de confianza?', 'Desde "Mi flota" puede buscar un conductor por nombre, usuario o código de socio, e invitarlo — recién queda en su flota cuando el conductor acepta.', 10],
            ['cliente', 'Seguridad', '¿Qué hago en una emergencia durante un viaje?', 'Use el botón SOS dentro de la pantalla de la carrera — avisa por correo a sus contactos de confianza con la ubicación y los datos del conductor.', 10],
            ['cliente', 'Suscripción', '¿Qué gano con un plan pago?', 'Más flotas, más conductores por flota, y otros beneficios según el plan — puede verlos y compararlos desde "Mi plan".', 10],
            ['cliente', 'Cuenta', '¿Cómo cambio mi contraseña o mis datos?', 'Desde su perfil (ícono de la esquina) puede editar nombre, correo, foto y ciudad.', 10],
            // Conductor
            ['conductor', 'Documentos', '¿Qué documentos tengo que subir?', 'Foto de su licencia y foto de su vehículo, desde "Mi perfil de conductor" — un admin las revisa antes de aparecer verificado.', 10],
            ['conductor', 'Aprobación', '¿Cuánto tarda la verificación?', 'La revisa un admin manualmente, no hay un tiempo fijo — mientras tanto puede seguir recibiendo carreras con normalidad.', 20],
            ['conductor', 'Tarifas', '¿Cómo configuro mi tarifa por km?', 'Desde "Mi perfil de conductor", campo "Tarifa por km" — puede declarar también una tarifa mínima propia, siempre que no supere el tope general de la plataforma.', 10],
            ['conductor', 'Suscripción', '¿Qué gano con un plan pago?', 'Más clientes de confianza, directorio público, e insignia de verificado según el plan — puede verlos y compararlos desde "Mi plan".', 10],
            ['conductor', 'Carreras', '¿Cómo acepto o rechazo una solicitud?', 'Le llega un aviso en la app (y por WhatsApp si conectó su número) — desde "Carreras" puede aceptar, rechazar, o contraofertar un precio distinto.', 10],
            ['conductor', 'Pagos', '¿Cómo elijo qué formas de pago acepto?', 'Desde "Mi perfil de conductor", active o desactive "Efectivo" y "Transferencia" — el cliente solo va a poder elegir entre las que usted tenga activas.', 20],
            ['conductor', 'Perfil', '¿Cómo aparezco en el directorio público?', 'Active "Aparecer en el directorio público" desde su perfil — disponible según su plan y a partir de cierta medalla por puntos.', 10],
            ['conductor', 'Viajes VAN', '¿Cómo publico un viaje en VAN?', 'Desde "Mis viajes VAN" (si su plan lo incluye), toque "Publicar" y complete ruta, fecha, cupos y precio por persona.', 10],
            // Ambos
            ['ambos', 'Problemas técnicos', 'La app no me deja iniciar sesión, ¿qué hago?', 'Revise que su usuario/correo/teléfono y contraseña sean correctos. Si sigue sin poder, use "¿Olvidó su contraseña?" desde la pantalla de inicio de sesión.', 100],
            ['ambos', 'Problemas técnicos', 'No me llegan los avisos de WhatsApp.', 'Tiene que escribirle primero al número oficial de Arka01 para abrir la ventana de 24 horas — la app le indica cómo cuando hace falta.', 110],
        ];

        foreach ($faqs as [$audience, $category, $question, $answer, $sortOrder]) {
            DB::table('faqs')->insert([
                'audience' => $audience,
                'category' => $category,
                'question' => $question,
                'answer' => $answer,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
