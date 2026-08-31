<?php

namespace App\Services;

use App\Events\ChatbotMessageLogged;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\CooperativeDriverMembership;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mensajes de texto libre por WhatsApp (pedido explícito del usuario): a
 * diferencia de WhatsAppVerificationSender (que manda plantilla HSM porque es
 * el primer contacto), esto solo funciona DENTRO de la ventana de 24 horas
 * que se abre cuando el usuario le escribe primero al número oficial — ver
 * App\Models\WhatsAppSession. Mandar texto libre fuera de esa ventana lo
 * rechaza la propia API de Meta, así que el llamador siempre tiene que
 * chequear `$user->hasActiveWhatsAppSession()` antes de usar esto.
 */
class WhatsAppFreeformSender
{
    public static function enabled(): bool
    {
        return filled(WhatsAppConfig::token()) && filled(WhatsAppConfig::phoneNumberId());
    }

    /**
     * Transcripción completa (pedido explícito del usuario: "ver cada
     * conversación con el bot") — un solo enganche para las 5 primitivas de
     * acá abajo, en vez de buscar cada uno de los lugares que llaman a
     * alguna de ellas: los métodos más específicos (sendNewRideAlert(),
     * sendCooperativeInvitationAlert(), etc.) ya llaman a estas por dentro.
     * Se registra también cuando falló el envío real (`$successful=false`)
     * — igual sirve para trazabilidad ("se intentó avisarle, no llegó").
     */
    private static function logOutbound(string $phoneE164, string $body, bool $successful, array $meta = []): void
    {
        $message = ChatbotMessage::query()->create([
            'phone' => $phoneE164,
            'user_id' => User::query()->where('phone', $phoneE164)->value('id'),
            'direction' => 'out',
            'body' => $body,
            'meta' => array_merge($meta, ['successful' => $successful]),
        ]);

        // Pedido explícito del usuario ("tener a todos los que me escriben y
        // poder responder desde allí") — el inbox del admin se actualiza en
        // vivo con TODO lo que sale, no solo lo que un admin escribe a mano:
        // también los avisos automáticos del bot y de carreras.
        broadcast(new ChatbotMessageLogged($message, ChatbotConversation::forPhone($phoneE164)->id))->toOthers();
    }

    /**
     * @param  string  $phoneE164  Ej. "+593991234567"
     * @param  string|null  $type  Key de WhatsAppRideAccess::NOTIFICATION_TYPES
     *                             (o null para lo que no es un aviso apagable) — solo
     *                             para trazabilidad/costos en ChatbotMessage.meta, ver
     *                             Admin\WhatsAppSettingController.
     */
    public static function sendText(string $phoneE164, string $message, ?string $type = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        // Timeout explícito (pedido explícito del usuario, tras encontrar un
        // job de cola colgado más de 60 segundos): sin esto, un HTTP a la API
        // de Meta que se queda esperando (red lenta, DNS colgado) podía
        // trabar todo un worker de cola indefinidamente — con `queue:work`
        // eso ya no tumba el worker entero, pero igual no tiene sentido
        // esperar de más por un solo mensaje de WhatsApp.
        $response = Http::withToken(WhatsAppConfig::token())
            ->timeout(10)
            ->post('https://graph.facebook.com/v20.0/'.WhatsAppConfig::phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phoneE164, '+'),
                'type' => 'text',
                'text' => ['body' => $message],
            ]);

        if ($response->failed()) {
            Log::warning('No se pudo enviar el mensaje libre de WhatsApp.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            // Monitoreo (roadmap de mejoras, sección 9): visible desde
            // /admin/monitoreo sin tener que entrar a storage/logs. Nunca se
            // guarda el token acá — solo el estado y el error que devolvió Meta.
            SystemEventLogger::log(
                eventType: 'whatsapp_send_failed',
                module: 'whatsapp',
                message: "No se pudo enviar el mensaje libre de WhatsApp a {$phoneE164}.",
                severity: 'error',
                context: ['status' => $response->status(), 'body' => $response->json()],
                channel: 'whatsapp',
                providerErrorCode: (string) $response->status(),
            );
        }

        self::logOutbound($phoneE164, $message, $response->successful(), array_filter(['type' => $type]));

        return $response->successful();
    }

    /** @param array<int, array{id:string,title:string}> $buttons */
    public static function sendButtons(string $phoneE164, string $message, array $buttons, ?string $type = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $response = Http::withToken(WhatsAppConfig::token())
            ->timeout(10)
            ->post('https://graph.facebook.com/v20.0/'.WhatsAppConfig::phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phoneE164, '+'),
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $message],
                    'action' => ['buttons' => collect($buttons)->take(3)->map(fn ($button) => [
                        'type' => 'reply',
                        'reply' => ['id' => $button['id'], 'title' => mb_substr($button['title'], 0, 20)],
                    ])->values()->all()],
                ],
            ]);

        self::logOutbound($phoneE164, $message, $response->successful(), array_filter(['buttons' => $buttons, 'type' => $type]));

        return $response->successful();
    }

    /**
     * Lista de WhatsApp (pedido explícito del usuario: "chatbot mas pro...
     * evitar que confirmen con numeros o escriban") — para cuando hay más de
     * 3 opciones, que es el máximo de botones reales que admite la API (ver
     * sendButtons() arriba). Mismo patrón de un solo POST a `/messages`.
     *
     * @param  array<int, array{id:string,title:string}>  $rows  Hasta 10 en total (límite de Meta).
     */
    public static function sendList(string $phoneE164, string $message, string $buttonLabel, array $rows, ?string $type = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $response = Http::withToken(WhatsAppConfig::token())
            ->timeout(10)
            ->post('https://graph.facebook.com/v20.0/'.WhatsAppConfig::phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phoneE164, '+'),
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'list',
                    'body' => ['text' => $message],
                    'action' => [
                        'button' => mb_substr($buttonLabel, 0, 20),
                        'sections' => [[
                            'rows' => collect($rows)->take(10)->map(fn ($row) => [
                                'id' => $row['id'],
                                // Límite duro de la API (fila de lista, no botón): 24
                                // caracteres — distinto del límite de 20 de sendButtons().
                                'title' => mb_substr($row['title'], 0, 24),
                            ])->values()->all(),
                        ]],
                    ],
                ],
            ]);

        self::logOutbound($phoneE164, $message, $response->successful(), array_filter(['list_rows' => $rows, 'type' => $type]));

        return $response->successful();
    }

    /**
     * Solicita una ubicacion con el selector nativo de WhatsApp.
     *
     * Al tocar el boton que renderiza WhatsApp, el usuario abre el mapa de
     * ubicaciones y responde con un mensaje `location`. Ese mensaje ya es
     * interpretado por WhatsAppWebhookController, por lo que no necesita
     * salir del chat ni abrir una pagina intermedia de Arka01.
     */
    public static function sendLocationRequest(string $phoneE164, string $message): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $response = Http::withToken(WhatsAppConfig::token())
            ->timeout(10)
            ->post('https://graph.facebook.com/v20.0/'.WhatsAppConfig::phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phoneE164, '+'),
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'location_request_message',
                    'body' => ['text' => $message],
                    'action' => ['name' => 'send_location'],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('No se pudo solicitar la ubicacion por WhatsApp.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        self::logOutbound($phoneE164, $message, $response->successful(), ['location_request' => true]);

        return $response->successful();
    }

    public static function sendLocation(string $phoneE164, float $lat, float $lng, string $name, ?string $address = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $response = Http::withToken(WhatsAppConfig::token())
            ->timeout(10)
            ->post('https://graph.facebook.com/v20.0/'.WhatsAppConfig::phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phoneE164, '+'),
                'type' => 'location',
                'location' => array_filter(['latitude' => $lat, 'longitude' => $lng, 'name' => $name, 'address' => $address]),
            ]);

        self::logOutbound($phoneE164, "📍 {$name}", $response->successful(), ['lat' => $lat, 'lng' => $lng, 'address' => $address]);

        return $response->successful();
    }

    /**
     * Tarjeta de contacto de WhatsApp de verdad (pedido explícito del
     * usuario: "cuando mande a soporte que mande un contacto") — a
     * diferencia de mandar el número como texto, esto llega como una
     * tarjeta tocable que la persona puede guardar o llamar directo desde
     * WhatsApp, mismo tipo de mensaje `contacts` que usa la propia Meta
     * Cloud API. Ver App\Models\ChatbotSetting (support_contact_name/phone,
     * editable desde /admin/chatbot) y EscalateToSupportHandler, que es
     * quien lo llama.
     *
     * @param  string  $contactPhoneE164  Ej. "+593991234567"
     */
    public static function sendContact(string $phoneE164, string $contactName, string $contactPhoneE164): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $response = Http::withToken(WhatsAppConfig::token())
            ->timeout(10)
            ->post('https://graph.facebook.com/v20.0/'.WhatsAppConfig::phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phoneE164, '+'),
                'type' => 'contacts',
                'contacts' => [[
                    'name' => ['formatted_name' => $contactName, 'first_name' => $contactName],
                    'phones' => [['phone' => $contactPhoneE164, 'type' => 'WORK']],
                ]],
            ]);

        self::logOutbound($phoneE164, "📇 Contacto: {$contactName} ({$contactPhoneE164})", $response->successful());

        return $response->successful();
    }

    /**
     * Aviso de carrera nueva (pedido explícito del usuario) — nombre del
     * cliente, dirección de recogida, distancia y valor aproximado, con un
     * link directo para abrir la app y aceptarla. Se usa tanto cuando se
     * crea la solicitud (RideRequestController) como cuando el despacho
     * secuencial le pasa el turno al siguiente candidato (RideDispatchAdvancer)
     * — mismo mensaje, dos disparadores. No hace nada si el conductor no
     * tiene la ventana de 24h abierta (le escribió "Hola" al número
     * oficial): fuera de esa ventana, Meta no deja mandar texto libre.
     */
    public static function sendNewRideAlert(User $driver, RideRequest $rideRequest): void
    {
        if (! WhatsAppRideAccess::notificationsEnabled()
            || ! WhatsAppRideAccess::notificationTypeEnabled('new_ride_alert')
            || ! $driver->phone
            || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $distanceKm = $driver->driverProfile?->current_lat !== null
            ? round(Haversine::distanceKm(
                (float) $driver->driverProfile->current_lat,
                (float) $driver->driverProfile->current_lng,
                (float) $rideRequest->origin_lat,
                (float) $rideRequest->origin_lng,
            ), 1)
            : null;

        // Pedido explícito del usuario: indicar cuánto tiempo tiene para
        // responder — solo aplica al despacho secuencial (una solicitud
        // DIRIGIDA también tiene vencimiento desde el bug encontrado en la
        // auditoría del flujo completo, pero SIN cascada — "pase al
        // siguiente conductor" sería falso ahí, así que esta línea sigue
        // siendo solo para la bolsa; ver isSequentialDispatch()).
        $secondsLeft = ($rideRequest->current_offer_expires_at && $rideRequest->isSequentialDispatch())
            ? max(0, $rideRequest->current_offer_expires_at->getTimestamp() - now()->getTimestamp())
            : null;

        $scheduledLine = $rideRequest->is_scheduled && $rideRequest->scheduled_at
            ? '📅 Programada para '.$rideRequest->scheduled_at->timezone('America/Guayaquil')->format('d/m/Y H:i')."\n"
            : '';
        // Pedido explícito del usuario: "en los whatsapp manda la fecha y
        // hora de la solicitud de la carrera" — antes esto solo salía si
        // era programada (scheduledLine, arriba); ahora sale siempre.
        // `->timezone('America/Guayaquil')` explícito, sin importar la zona
        // horaria configurada en el servidor (ver config/app.php) — esto es
        // lo que un conductor en Ecuador lee literalmente en el mensaje, no
        // pasa por el navegador para corregirse solo como sí pasa con las
        // fechas que se muestran dentro de la app.
        $requestedLine = '🕐 Solicitada: '.$rideRequest->requested_at->timezone('America/Guayaquil')->format('d/m/Y H:i')."\n";
        // Pedido explícito del usuario: "que diga origen, destino, y los km
        // desde hasta y km de donde tengo que ir a buscar al pasajero" — antes
        // solo salía el origen y una sola distancia ambigua (la del conductor
        // hasta el pasajero, pero etiquetada solo "Distancia"). Ahora van las
        // dos por separado: el tramo del viaje en sí, y lo que falta manejar
        // para llegar a recogerlo.
        $tripDistanceKm = $rideRequest->distance_km !== null ? round((float) $rideRequest->distance_km, 1) : null;
        // Bug real reportado por el usuario ("revisa si le llega al
        // conductor igual"): current_offered_price es solo el tramo final
        // (última parada → destino, ver RideRequestCreator::create()) — sin
        // sumar stops_price el conductor aceptaba por WhatsApp creyendo que
        // era un viaje directo más barato, sin saber que había paradas.
        $stops = $rideRequest->stops()->orderBy('sequence')->get();
        $totalPrice = $rideRequest->driverPayEstimate();
        $stopsLine = $stops->isEmpty() ? '' : 'Paradas: '.$stops->count()."\n".$stops->map(
            fn ($stop, $i) => '  '.($i + 1).'. '.($stop->address ?? 'ver en la app')
        )->implode("\n")."\n";

        $message = " ¡Carrera nueva de {$rideRequest->client->name}!\n"
            .$requestedLine
            .$scheduledLine
            .'Recogida: '.($rideRequest->origin_address ?? 'ver en la app')."\n"
            .$stopsLine
            .'Destino: '.($rideRequest->destination_address ?? 'ver en la app')."\n"
            .($tripDistanceKm !== null ? "Distancia del viaje: {$tripDistanceKm} km\n" : '')
            .($distanceKm !== null ? "Km hasta el pasajero: {$distanceKm} km\n" : '')
            .($rideRequest->cooperative_id ? "Pago de la cooperativa: \${$totalPrice}\n" : "Valor aproximado: \${$totalPrice}\n")
            .($secondsLeft !== null ? "⏱ Tiene {$secondsLeft} segundos para aceptar antes de que pase al siguiente conductor.\n" : '')
            ."\nAbra Arka01 para aceptarla:\n".route('rides.index')
            // Pedido explícito del usuario: un conductor puede seguir
            // recibiendo estos avisos por WhatsApp aunque la app esté en
            // segundo plano (ver DriverProfile::isReachable()) — si no
            // quiere más, tiene que desconectarse a propósito desde la app,
            // no alcanza con cerrarla o dejarla en segundo plano.
            ."\n\n¿No quiere más solicitudes? Desconéctese desde la app para dejar de recibirlas.";

        if (WhatsAppRideAccess::driverCanOperate($driver)) {
            self::sendButtons($driver->phone, $message, [
                ['id' => 'ride_accept:'.$rideRequest->id, 'title' => 'Aceptar'],
                ['id' => 'ride_reject:'.$rideRequest->id, 'title' => 'No tomar'],
            ], 'new_ride_alert');
        } else {
            self::sendText($driver->phone, $message, 'new_ride_alert');
        }
    }

    /**
     * Aviso de invitación de cooperativa (bug reportado por el usuario: "el
     * conductor que no le llega la solicitud que le manda la cooperativa
     * para que se una") — antes solo se mandaba por Web Push
     * (CooperativeDriverInvitationPushNotification), que falla en
     * silencio si el conductor nunca dio permiso o no tiene el service
     * worker activo. Mismo criterio que sendNewRideAlert(): si tiene la
     * ventana de 24h abierta, también le llega por WhatsApp, que no
     * depende de ningún permiso del navegador.
     */
    public static function sendCooperativeInvitationAlert(User $driver, CooperativeDriverMembership $membership): void
    {
        if (! WhatsAppRideAccess::notificationTypeEnabled('cooperative_invitation') || ! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $message = "🤝 {$membership->cooperative->name} quiere vincularlo como conductor afiliado.\n\n"
            .'Revise la invitación en Arka01: '.route('cooperative-driver-invitations.index');

        self::sendText($driver->phone, $message, 'cooperative_invitation');
    }

    public static function sendRideAcceptedToClient(Ride $ride): void
    {
        $client = $ride->client;
        if (! self::notificationsEnabledFor($client, 'ride_accepted')) {
            return;
        }

        $profile = $ride->driver->driverProfile;
        // Pedido explícito del usuario: origen, destino y las dos distancias
        // (viaje y cuánto le falta al conductor para llegar) — mismo criterio
        // que sendNewRideAlert(), acá del lado del cliente.
        $tripDistanceKm = $ride->distance_km !== null ? round((float) $ride->distance_km, 1) : null;
        $driverDistanceKm = $profile?->current_lat !== null
            ? round(Haversine::distanceKm(
                (float) $profile->current_lat,
                (float) $profile->current_lng,
                (float) $ride->origin_lat,
                (float) $ride->origin_lng,
            ), 1)
            : null;

        $message = "✅ {$ride->driver->name} aceptó su carrera y ya va en camino.\n"
            .'Vehículo: '.trim(($profile?->vehicle_make ?? '').' '.($profile?->vehicle_model ?? '')).' · '.($profile?->vehicle_color ?? 'sin color')."\n"
            .'Placa: '.($profile?->maskedPlate() ?? 'ver en la app')."\n"
            .'Recogida: '.($ride->origin_address ?? 'ver en la app')."\n"
            .'Destino: '.($ride->destination_address ?? 'ver en la app')."\n"
            .($tripDistanceKm !== null ? "Distancia del viaje: {$tripDistanceKm} km\n" : '')
            .($driverDistanceKm !== null ? "El conductor está a {$driverDistanceKm} km de usted\n" : '')
            ."\nPuede seguir el viaje en Arka01: ".route('rides.show', $ride);

        // Pedido explícito del usuario: botón simple para cancelar sin salir
        // de WhatsApp — ver WhatsAppRideActionHandler::cancelRide(), reusa el
        // mismo RideController::cancel() de la app con un motivo fijo
        // ("Otro motivo"), sin pedirle nada más al cliente.
        if (WhatsAppRideAccess::clientCanBook()) {
            self::sendButtons($client->phone, $message, [
                ['id' => 'ride_cancel:'.$ride->id, 'title' => 'Cancelar carrera'],
            ], 'ride_accepted');
        } else {
            self::sendText($client->phone, $message, 'ride_accepted');
        }
    }

    /**
     * Los siguientes 4 avisos completan la misma serie de eventos que ya
     * tiene su equivalente en Web Push (Ride*PushNotification, ver
     * RideController) pero le faltaba a WhatsApp — pedido explícito del
     * usuario, con caso real: "cuando el conductor iba por el cliente no
     * le llego el mensaje... y cuando termino la carrera tampoco le dijo
     * nada". `sendRideAcceptedToClient()` de arriba ya cubre el aviso
     * inicial; para una carrera INMEDIATA (la mayoría) ese es el único
     * momento en que el conductor "arranca" — start() solo aplica a
     * carreras PROGRAMADAS (ver el comentario de RideController::start()),
     * así que este aviso llega recién ahí. arrived()/pickedUp()/complete()
     * sí pasan siempre, para cualquier carrera.
     */
    public static function sendRideStartedToClient(Ride $ride): void
    {
        $client = $ride->client;
        if (! self::notificationsEnabledFor($client, 'ride_started')) {
            return;
        }

        self::sendText($client->phone, "🚗 {$ride->driver->name} ya salió a buscarlo.\n\nSiga el viaje en Arka01: ".route('rides.show', $ride), 'ride_started');
    }

    public static function sendRideArrivedToClient(Ride $ride): void
    {
        $client = $ride->client;
        if (! self::notificationsEnabledFor($client, 'ride_arrived')) {
            return;
        }

        self::sendText($client->phone, "📍 {$ride->driver->name} llegó y lo está esperando.", 'ride_arrived');
    }

    public static function sendRidePickedUpToClient(Ride $ride): void
    {
        $client = $ride->client;
        if (! self::notificationsEnabledFor($client, 'ride_picked_up')) {
            return;
        }

        self::sendText($client->phone, '▶️ Su viaje comenzó hacia '.($ride->destination_address ?? 'su destino').'.', 'ride_picked_up');
    }

    /**
     * Pedido explícito del usuario ("que califique por allí también") — no
     * solo avisa que terminó, deja calificar sin salir de WhatsApp: una
     * lista real con las 5 estrellas. `WhatsAppPendingRequestHandler`... no,
     * ver `WhatsAppRatingHandler` — 5 estrellas guarda directo, menos de 5
     * pide el motivo (obligatorio, ver ReviewController::store()) con una
     * segunda lista. Quien prefiera hacerlo desde la app también puede.
     */
    public static function sendRideCompletedToClient(Ride $ride): void
    {
        $client = $ride->client;
        if (! self::notificationsEnabledFor($client, 'ride_completed')) {
            return;
        }

        $rows = collect(range(5, 1))
            ->map(fn (int $stars) => ['id' => "wa_rate:{$ride->id}:{$stars}", 'title' => str_repeat('⭐', $stars)])
            ->all();

        self::sendList(
            $client->phone,
            "✅ Carrera completada — \${$ride->settled_price}.\n\n¿Cómo le fue con {$ride->driver->name}? Califique tocando una opción, o revise el recibo completo en Arka01: ".route('rides.show', $ride),
            'Calificar',
            $rows,
            'ride_completed'
        );
    }

    private static function notificationsEnabledFor(User $user, string $type): bool
    {
        return WhatsAppRideAccess::notificationsEnabled()
            && WhatsAppRideAccess::notificationTypeEnabled($type)
            && filled($user->phone)
            && $user->hasActiveWhatsAppSession();
    }

    public static function sendScheduledRideReminder(User $driver, Ride $ride): void
    {
        if (! WhatsAppRideAccess::notificationTypeEnabled('scheduled_reminder') || ! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $time = $ride->rideRequest->scheduled_at->format('H:i');
        // Pedido explícito del usuario: destino y las dos distancias, mismo
        // criterio que sendNewRideAlert()/sendRideAcceptedToClient().
        $tripDistanceKm = $ride->distance_km !== null ? round((float) $ride->distance_km, 1) : null;
        $driverDistanceKm = $driver->driverProfile?->current_lat !== null
            ? round(Haversine::distanceKm(
                (float) $driver->driverProfile->current_lat,
                (float) $driver->driverProfile->current_lng,
                (float) $ride->origin_lat,
                (float) $ride->origin_lng,
            ), 1)
            : null;

        $message = "⏰ Su carrera programada está próxima.\n"
            ."Cliente: {$ride->client->name}\n"
            ."Hora: {$time}\n"
            .'Recogida: '.($ride->origin_address ?? 'ver en la app')."\n"
            .'Destino: '.($ride->destination_address ?? 'ver en la app')."\n"
            .($tripDistanceKm !== null ? "Distancia del viaje: {$tripDistanceKm} km\n" : '')
            .($driverDistanceKm !== null ? "Km hasta el pasajero: {$driverDistanceKm} km\n" : '')
            ."\nAbra Arka01 para revisar e iniciar el viaje: ".route('rides.show', $ride);

        // Pedido explícito del usuario: botón simple para cancelar sin salir
        // de WhatsApp — ver WhatsAppRideActionHandler::cancelRide().
        if (WhatsAppRideAccess::driverCanOperate($driver)) {
            self::sendButtons($driver->phone, $message, [
                ['id' => 'ride_cancel:'.$ride->id, 'title' => 'Cancelar carrera'],
            ], 'scheduled_reminder');
        } else {
            self::sendText($driver->phone, $message, 'scheduled_reminder');
        }
    }

    /**
     * Bug reportado por el usuario: una carrera programada cuya hora ya
     * pasó se quedaba mostrando "Iniciar viaje" sin ningún aviso — este es
     * el mensaje de WhatsApp, simétrico a sendScheduledRideReminder() pero
     * DESPUÉS de la hora en vez de antes. No cancela nada solo por
     * mandarse (puede estar en camino, con tráfico) — solo avisa.
     */
    public static function sendScheduledRideOverdueAlert(User $driver, Ride $ride): void
    {
        if (! WhatsAppRideAccess::notificationTypeEnabled('scheduled_overdue') || ! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $time = $ride->rideRequest->scheduled_at->format('H:i');
        // Pedido explícito del usuario: destino y las dos distancias, mismo
        // criterio que sendNewRideAlert()/sendScheduledRideReminder().
        $tripDistanceKm = $ride->distance_km !== null ? round((float) $ride->distance_km, 1) : null;
        $driverDistanceKm = $driver->driverProfile?->current_lat !== null
            ? round(Haversine::distanceKm(
                (float) $driver->driverProfile->current_lat,
                (float) $driver->driverProfile->current_lng,
                (float) $ride->origin_lat,
                (float) $ride->origin_lng,
            ), 1)
            : null;

        $message = "⚠️ Su carrera programada ya debería haber empezado.\n"
            ."Cliente: {$ride->client->name}\n"
            ."Hora: {$time}\n"
            .'Recogida: '.($ride->origin_address ?? 'ver en la app')."\n"
            .'Destino: '.($ride->destination_address ?? 'ver en la app')."\n"
            .($tripDistanceKm !== null ? "Distancia del viaje: {$tripDistanceKm} km\n" : '')
            .($driverDistanceKm !== null ? "Km hasta el pasajero: {$driverDistanceKm} km\n" : '')
            ."\nAbra Arka01 para iniciarla, o avísele al cliente si va a demorar: ".route('rides.show', $ride);

        // Pedido explícito del usuario: botón simple para cancelar sin salir
        // de WhatsApp — ver WhatsAppRideActionHandler::cancelRide().
        if (WhatsAppRideAccess::driverCanOperate($driver)) {
            self::sendButtons($driver->phone, $message, [
                ['id' => 'ride_cancel:'.$ride->id, 'title' => 'Cancelar carrera'],
            ], 'scheduled_overdue');
        } else {
            self::sendText($driver->phone, $message, 'scheduled_overdue');
        }
    }

    /**
     * Pedido explícito del usuario: avisarle al conductor que se le acabó el
     * tiempo para responder una oferta del despacho secuencial — antes se
     * enteraba solo por la app (si la tenía abierta); ahora también por
     * WhatsApp, igual que el aviso de la oferta en sí.
     */
    public static function sendOfferExpiredNotice(User $driver): void
    {
        if (! WhatsAppRideAccess::notificationTypeEnabled('offer_expired') || ! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $message = '⏱ Se le acabó el tiempo para responder — esa carrera ya pasó a otro conductor.';

        self::sendText($driver->phone, $message, 'offer_expired');
    }

    /**
     * Pedido explícito del usuario: avisar al conductor cuando se desconecta
     * — ya sea porque cerró sesión/se puso "no disponible" a propósito, o
     * porque el barrido (App\Console\Commands\SweepStaleDriverAvailability)
     * lo detectó sin ping reciente — para animarlo a volver a activarse. Se
     * llama SIEMPRE desde App\Jobs\NotifyDriverDisconnectedByWhatsApp (nunca
     * sincrónico desde el request/comando que lo dispara), y el chequeo de
     * la ventana de 24h se hace acá, en el momento real de mandar el
     * mensaje — no al encolar el job, que puede tardar un rato en correr.
     */
    public static function sendDisconnectedAlert(User $driver): void
    {
        if (! WhatsAppRideAccess::notificationTypeEnabled('driver_disconnected') || ! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $message = "👋 Se desconectó de Arka01 y dejó de recibir carreras.\n"
            .'Puede volver a activarse desde acá mismo o desde la app.';

        // Botón "Conectarme" (pedido explícito del usuario): mismo id que ya
        // reconoce App\Services\Chatbot\WhatsAppDriverConnectHandler::handleRegisteredDriver().
        // Ese handler solo entra si el texto es exactamente "conectarme" o si
        // la conversación ya está en un estado que empiece con "WA_DRIVER_"
        // — como este aviso es proactivo (el conductor no escribió nada
        // antes), hay que dejar ese estado seteado de antemano para que el
        // toque del botón se reconozca igual que si lo hubiera escrito.
        ChatbotConversation::forPhone($driver->phone)
            ->update(['pending_intent' => 'WA_DRIVER_STATUS', 'context' => null, 'last_message_at' => now()]);

        self::sendButtons($driver->phone, $message, [
            ['id' => 'wa_driver_connect', 'title' => 'Conectarme'],
        ], 'driver_disconnected');
    }

    /**
     * Pedido explícito del usuario ("que le mande un mensaje que se le
     * cerrar la sesion... para restablecer la sesion de whatsapp"): avisar
     * al conductor antes de que Meta le cierre la ventana de 24 horas, con
     * un botón — tocar CUALQUIER botón ya cuenta como un mensaje entrante
     * (WhatsAppWebhookController::openWindowFor()), así que alcanza con que
     * responda algo para que la ventana se reabra sola; el botón "Seguir
     * conectado" solo existe para darle algo concreto que tocar. Encolada
     * desde App\Console\Commands\NotifyExpiringWhatsAppSessions, nunca
     * sincrónica, mismo criterio que sendDisconnectedAlert().
     */
    public static function sendSessionExpiringSoonNotice(User $driver): void
    {
        if (! WhatsAppRideAccess::notificationTypeEnabled('session_expiring_soon') || ! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        ChatbotConversation::forPhone($driver->phone)
            ->update(['pending_intent' => 'WA_DRIVER_STATUS', 'context' => null, 'last_message_at' => now()]);

        self::sendButtons(
            $driver->phone,
            '⏳ Su sesión de WhatsApp con Arka01 está por cerrarse. Si no escribe nada, dejará de recibir avisos por acá hasta que vuelva a escribirnos.',
            [
                ['id' => 'wa_session_keepalive', 'title' => 'Seguir conectado'],
                ['id' => 'wa_driver_disconnect', 'title' => 'Desconectarme'],
            ],
            'session_expiring_soon'
        );
    }

    /**
     * Pedido explícito del usuario: verificar el número desde el que se
     * conecta contra el que declaró en su perfil de conductor — si no
     * coincide, no se abre la ventana en su nombre (los avisos siempre se
     * mandan al número del perfil, no al que acaba de escribir), y se le
     * avisa acá mismo por qué. Se manda al número que ACABA de escribir
     * (`$toE164`, no `$intendedDriver->phone`) — es el único que Meta nos
     * habilita a esta altura, todavía no hay ninguna ventana abierta a
     * nombre del conductor real.
     */
    public static function sendPhoneMismatchNotice(string $toE164, User $intendedDriver): void
    {
        $registeredHint = $intendedDriver->phone ? substr($intendedDriver->phone, -4) : null;

        $message = '⚠️ Este número no coincide con el que tiene registrado en su perfil de Arka01'
            .($registeredHint ? " (termina en {$registeredHint})" : '').".\n\n"
            .'Para conectar los avisos de carreras, escríbanos desde ese mismo número, o actualice su teléfono desde su perfil en la app.';

        self::sendText($toE164, $message);
    }

    /**
     * Bug reportado por el usuario (caso real visto en el webhook: una
     * cuenta distinta trató de conectar un número que ya estaba verificado a
     * nombre de otro conductor): un número de WhatsApp es de una sola cuenta
     * a la vez — no se abre la ventana en nombre de quien lo intenta de
     * nuevo, se le avisa por qué. No se manda ningún dato del dueño real del
     * número (privacidad) — solo que ya está en uso.
     */
    public static function sendNumberAlreadyRegisteredNotice(string $toE164): void
    {
        $message = "⚠️ Este número de WhatsApp ya está conectado a otra cuenta de Arka01.\n\n"
            .'Si le parece un error, escríbanos, o actualice su número desde su perfil de conductor con otro número que no esté en uso.';

        self::sendText($toE164, $message);
    }

    /**
     * Sesión única por cuenta (pedido explícito del usuario, caso real: no
     * sabía en qué navegador había quedado logueado): el código para cerrar
     * esa otra sesión, mandado por acá cuando el dueño tiene la ventana de
     * 24h abierta — más rápido que esperar el correo. Se manda SIEMPRE al
     * teléfono declarado en el perfil, nunca a uno que alguien más escriba,
     * mismo criterio que el resto de los avisos de esta clase. Incluye
     * también un link para bloquear la cuenta de inmediato si no fue el
     * dueño real quien lo pidió (pedido explícito del usuario: "si no es
     * usted, solicitar bloquear la cuenta" — no alcanzaba con "ignore este
     * mensaje").
     */
    /**
     * Pedido explícito del usuario: en vez de que "Pedir código" sea el
     * primer paso (y ahí recién intentar mandarlo por WhatsApp, fallando en
     * silencio a correo si la ventana de 24h no estaba abierta), el widget
     * de sesión única en Auth/Login.vue ahora invita a escribir primero al
     * WhatsApp oficial — este es el "bot" confirmando que ya puede volver a
     * la web y tocar "Pedir código" de una, con el WhatsApp ya listo para
     * recibirlo. Ver WhatsAppWebhookController::receive() (detecta la frase
     * exacta de Utils/whatsapp.js::buildSessionRecoveryWhatsAppUrl()) y
     * SessionTakeoverController::request() (el que manda el código en sí).
     */
    public static function sendSessionRecoveryPrompt(User $user): void
    {
        if (! $user->phone || ! $user->hasActiveWhatsAppSession()) {
            return;
        }

        $message = '✅ ¡Listo! Ya puede volver a la página de inicio de sesión de Arka01 y tocar "Pedir código" — se lo mandamos por acá.';

        self::sendText($user->phone, $message);
    }

    public static function sendSessionTakeoverCode(User $user, string $code, string $lockUrl): void
    {
        if (! $user->phone || ! $user->hasActiveWhatsAppSession()) {
            return;
        }

        $message = "🔐 Alguien solicitó cerrar su otra sesión de Arka01 para entrar desde un dispositivo nuevo.\n\n"
            ."Su código es: {$code}\n"
            ."Vence en 10 minutos.\n\n"
            ."¿No fue usted? Bloquee su cuenta de inmediato:\n{$lockUrl}";

        self::sendText($user->phone, $message);
    }
}
