<?php

namespace App\Services\Chatbot;

use App\Http\Controllers\RideRequestController;
use App\Jobs\NotifyWhatsAppStillSearchingForDriver;
use App\Models\ChatbotConversation;
use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\GoogleGeocodingService;
use App\Services\Haversine;
use App\Services\WhatsAppFreeformSender;
use App\Services\WhatsAppRideAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Flujo conversacional de solicitud de transporte; usa el mismo controlador que la web. */
class WhatsAppRideBookingHandler
{
    public function __construct(
        private readonly GoogleGeocodingService $geocoder,
    ) {}

    public function handle(string $phone, ?User $user, string $text, array $metadata, ChatbotConversation $conversation): bool
    {
        $state = $conversation->pending_intent;
        $startsBooking = in_array(MessageNormalizer::normalize($text), [
            'pedir carrera', 'solicitar carrera', 'pedir un viaje', 'solicitar viaje', 'viaje',
        ], true);
        // Pedido explícito del usuario: "si realmente no se encontro que la
        // misma plataforma indique y le mande un boton que diga pedir
        // nuevamente y que intente nuevamente con esos mismo parametros" —
        // llega como acción propia (RideDispatchAdvancer::notifyWhatsAppExpired()),
        // sin depender de ningún estado de conversación previo, igual que
        // "pedir carrera" arranca el flujo desde cero.
        $isRetry = (bool) preg_match('/^wa_retry_request:(\d+)$/', $text, $retryMatch);

        if (! $startsBooking && ! $isRetry && ! str_starts_with((string) $state, 'WA_BOOKING_') && $state !== 'WA_PRIVACY_CONSENT') {
            return false;
        }

        if (! WhatsAppRideAccess::clientCanBook()) {
            WhatsAppFreeformSender::sendText($phone, 'Por ahora las solicitudes por WhatsApp están pausadas. Puede pedir su carrera desde Arka01.');

            return true;
        }

        // Pedido explícito del usuario, tras probar el bot con su propio
        // número de conductor, y luego insistiendo: "la idea era que lo
        // registraba automaticamente como invitado o tomaba el nombre de su
        // perfil". Un conductor SÍ puede pedir una carrera por WhatsApp con
        // su propio número — no se le crea otra cuenta (el teléfono ya es
        // suyo) ni cambia de rol, la carrera queda a nombre de su cuenta tal
        // cual, reusando el nombre que ya tiene (por eso más abajo el paso
        // de "¿cuál es su nombre?" solo se pide `if (! $user)`, nunca acá).
        // RideRequestController::store() necesita el flag
        // `whatsapp_guest_booking` para permitir esta única excepción — ver
        // el comentario allá. Admin y cooperativa siguen bloqueados: pedir
        // una carrera no tiene sentido para esas cuentas.
        if ($user && ! $user->isClient() && ! $user->isDriver()) {
            $reason = $user->is_admin
                ? 'Este número es de una cuenta de administrador en Arka01, y esas no piden carreras.'
                : 'Este número está registrado como cooperativa en Arka01, y las cooperativas no piden carreras directamente.';
            WhatsAppFreeformSender::sendText($phone, $reason);

            return true;
        }

        if ($isRetry) {
            return $this->retryExpiredRequest($phone, $user, (int) $retryMatch[1], $conversation);
        }

        if ($startsBooking) {
            if (! $user?->whatsapp_privacy_accepted_at) {
                $notice = WhatsAppSetting::current()->privacy_notice_text
                    ?: 'Usaremos su número, ubicaciones y datos del viaje para gestionar el transporte y la seguridad del servicio.';
                // Pedido explícito del usuario: si el número no está
                // registrado, este mismo paso también avisa que se va a
                // crear la cuenta — sin esto, la cuenta se creaba en
                // WA_BOOKING_NAME sin haber avisado antes que eso iba a pasar.
                if (! $user) {
                    $notice .= "\n\nComo es la primera vez que nos escribe desde este número, vamos a crear su cuenta de cliente para poder gestionar su carrera.";
                }
                $this->setState($conversation, 'WA_PRIVACY_CONSENT', []);
                WhatsAppFreeformSender::sendButtons($phone, "Antes de continuar:\n{$notice}\n\n¿Acepta el tratamiento de estos datos?", [
                    ['id' => 'wa_privacy_accept', 'title' => 'Acepto'],
                    ['id' => 'wa_privacy_decline', 'title' => 'No acepto'],
                ]);

                return true;
            }

            return $this->askWhen($phone, $conversation);
        }

        if ($state === 'WA_PRIVACY_CONSENT') {
            if ($text !== 'wa_privacy_accept') {
                $this->clear($conversation);
                WhatsAppFreeformSender::sendText($phone, 'Entendido. No guardaremos una solicitud. Puede consultar el aviso de privacidad desde Arka01.');

                return true;
            }
            if (! $user) {
                $this->setState($conversation, 'WA_BOOKING_NAME', ['privacy_accepted_at' => now()->toIso8601String()]);
                WhatsAppFreeformSender::sendText($phone, 'Para identificar al pasajero, ¿cuál es su nombre y apellido? No necesita correo ni contraseña para continuar.');

                return true;
            }
            $user->forceFill(['whatsapp_privacy_accepted_at' => now()])->save();

            return $this->askWhen($phone, $conversation);
        }

        if ($state === 'WA_BOOKING_NAME') {
            $name = trim($text);
            if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
                WhatsAppFreeformSender::sendText($phone, 'Escriba un nombre válido, por ejemplo: Ana López.');

                return true;
            }
            $this->setState($conversation, 'WA_BOOKING_NAME_CONFIRM', ['name' => $name]);
            WhatsAppFreeformSender::sendButtons($phone, "¿Confirmamos el nombre: {$name}?", [
                ['id' => 'wa_name_confirm', 'title' => 'Sí'],
                ['id' => 'wa_name_retry', 'title' => 'Cambiar'],
            ]);

            return true;
        }

        // Pedido explícito del usuario: "confirmar el nombre con dos
        // botones si, cambiar, y luego continua" — antes se creaba la
        // cuenta apenas se escribía el nombre, sin poder corregir un typo.
        if ($state === 'WA_BOOKING_NAME_CONFIRM') {
            if ($text === 'wa_name_retry') {
                $this->setState($conversation, 'WA_BOOKING_NAME', []);
                WhatsAppFreeformSender::sendText($phone, 'Para identificar al pasajero, ¿cuál es su nombre y apellido?');

                return true;
            }
            if ($text !== 'wa_name_confirm') {
                WhatsAppFreeformSender::sendText($phone, 'Toque uno de los botones para continuar.');

                return true;
            }

            $name = ($conversation->context ?? [])['name'] ?? null;
            if (! $name) {
                $this->clear($conversation);
                WhatsAppFreeformSender::sendText($phone, 'Algo falló, escriba "pedir carrera" para volver a empezar.');

                return true;
            }

            $user = DB::transaction(function () use ($phone, $name) {
                $created = User::query()->create([
                    'name' => $name,
                    'email' => 'whatsapp+'.Str::lower(Str::random(16)).'@guest.arka01.local',
                    'password' => Str::random(48),
                    'phone' => $phone,
                    'role' => 'cliente',
                    'whatsapp_privacy_accepted_at' => now(),
                ]);
                $created->forceFill(['phone_verified_at' => now()])->save();
                Fleet::query()->create(['owner_user_id' => $created->id, 'name' => 'Mi flota']);

                return $created;
            });
            $conversation->update(['user_id' => $user->id]);

            return $this->askWhen($phone, $conversation);
        }

        $context = $conversation->context ?? [];
        if ($state === 'WA_BOOKING_WHEN') {
            if ($text === 'wa_when_scheduled') {
                $this->setState($conversation, 'WA_BOOKING_SCHEDULE', []);
                WhatsAppFreeformSender::sendText($phone, 'Indique fecha y hora en formato DD/MM/AAAA HH:MM. Ejemplo: 25/08/2026 14:30.');

                return true;
            }
            $context['is_scheduled'] = false;

            return $this->askLocation($phone, $conversation, 'WA_BOOKING_ORIGIN', $context, '¿Desde dónde le recogemos? Comparta su ubicación o escriba una dirección completa o coordenadas.', $user);
        }

        if ($state === 'WA_BOOKING_SCHEDULE') {
            try {
                $scheduledAt = Carbon::createFromFormat('d/m/Y H:i', trim($text), config('app.timezone'));
                if ($scheduledAt->lessThan(now()->addMinutes(30))) {
                    throw new \RuntimeException;
                }
            } catch (Throwable) {
                WhatsAppFreeformSender::sendText($phone, 'La fecha debe ser válida y tener al menos 30 minutos de anticipación. Ejemplo: 25/08/2026 14:30.');

                return true;
            }
            $context['is_scheduled'] = true;
            $context['scheduled_at'] = $scheduledAt->toIso8601String();

            return $this->askLocation($phone, $conversation, 'WA_BOOKING_ORIGIN', $context, '¿Desde dónde le recogemos? Comparta ubicación, dirección o coordenadas.', $user);
        }

        if (in_array($state, ['WA_BOOKING_ORIGIN', 'WA_BOOKING_DESTINATION'], true)) {
            $field = $state === 'WA_BOOKING_ORIGIN' ? 'origin' : 'destination';

            // Pedido explícito del usuario ("cuando pida nuevamente
            // mostrarle las ubicaciones que ha solicitado para volverlas a
            // repetir") — tocar una de la lista que armó askLocation() usa
            // la dirección y coordenadas EXACTAS de una carrera anterior,
            // sin volver a geocodificar ni pedir confirmación (ya se
            // confirmó la primera vez que se usó).
            if ($text === 'wa_recent_new') {
                $message = $state === 'WA_BOOKING_ORIGIN'
                    ? '¿Desde dónde le recogemos?'
                    : '¿A dónde vamos?';
                $this->sendNativeLocationRequest($phone, $message);

                return true;
            }
            if (preg_match('/^wa_recent_'.$field.':(\d+)$/', $text, $match) && isset($context['recent_'.$field.'_options'][(int) $match[1]])) {
                $point = $context['recent_'.$field.'_options'][(int) $match[1]];
                unset($context['recent_origin_options'], $context['recent_destination_options']);

                return $this->commitPoint($phone, $user, $conversation, $state, $context, $point);
            }

            // Pedido explícito del usuario ("las direcciones que mete el
            // cliente por descripciones aun el bot no las detecta") — sin
            // esto, Places siempre sesgaba la búsqueda hacia Guayaquil (ver
            // GoogleGeocodingService::findPlace()), aunque el cliente
            // estuviera pidiendo algo en Quito o cualquier otra ciudad.
            // Para el ORIGEN se usa la ciudad registrada del cliente (mejor
            // pista disponible antes de saber nada del viaje); para el
            // DESTINO, el origen recién confirmado — casi siempre más
            // cerca de la realidad que la ciudad de registro.
            $biasPoint = $state === 'WA_BOOKING_DESTINATION'
                ? ($context['origin'] ?? null)
                : ($user?->city ? ['lat' => (float) $user->city->lat, 'lng' => (float) $user->city->lng] : null);
            $point = $this->resolvePoint($text, $metadata, $biasPoint);
            if (! $point) {
                WhatsAppFreeformSender::sendText($phone, 'No pude ubicar ese punto. Comparta la ubicación desde WhatsApp o escriba calle, sector y ciudad.');

                return true;
            }

            // Pedido explícito del usuario, con captura real: escribió
            // "Coronel y Calicuchima" y el bot no lo entendió — ahora
            // GoogleGeocodingService también prueba con Places (mejor para
            // esquinas/lugares sueltos), pero sigue siendo una ADIVINANZA a
            // partir de texto libre. "Que le mande lo que te retorna google
            // map para que confirme": una ubicación COMPARTIDA de WhatsApp
            // (coordenadas exactas del GPS) no necesita esto — solo cuando
            // se resolvió a partir de texto.
            if (isset($metadata['location']['lat'], $metadata['location']['lng'])) {
                return $this->commitPoint($phone, $user, $conversation, $state, $context, $point);
            }

            $context['pending_point'] = $point;
            $context['pending_point_state'] = $state;
            $this->setState($conversation, 'WA_BOOKING_CONFIRM_POINT', $context);
            WhatsAppFreeformSender::sendButtons($phone, "📍 {$point['address']}\n\n¿Es correcto?", [
                ['id' => 'wa_point_confirm', 'title' => 'Sí, es correcto'],
                ['id' => 'wa_point_retry', 'title' => 'Escribir de nuevo'],
            ]);

            return true;
        }

        if ($state === 'WA_BOOKING_CONFIRM_POINT') {
            $originalState = $context['pending_point_state'] ?? 'WA_BOOKING_ORIGIN';

            if ($text === 'wa_point_retry') {
                unset($context['pending_point'], $context['pending_point_state']);
                $message = $originalState === 'WA_BOOKING_ORIGIN'
                    ? '¿Desde dónde le recogemos? Comparta su ubicación o escriba una dirección completa o coordenadas.'
                    : '¿A dónde vamos? Comparta ubicación, dirección o coordenadas.';

                return $this->askLocation($phone, $conversation, $originalState, $context, $message, $user);
            }

            if ($text !== 'wa_point_confirm' || ! isset($context['pending_point'])) {
                WhatsAppFreeformSender::sendText($phone, 'Toque uno de los botones para continuar.');

                return true;
            }

            $point = $context['pending_point'];
            unset($context['pending_point'], $context['pending_point_state']);

            return $this->commitPoint($phone, $user, $conversation, $originalState, $context, $point);
        }

        if ($state === 'WA_BOOKING_PAX') {
            // Pedido explícito del usuario: botones de cantidad en vez de
            // texto libre, para que sea más rápido — se acepta también un
            // número escrito a mano (alguien puede tipear "5" igual, aunque
            // los botones no lo ofrezcan como opción exacta).
            $pax = match (true) {
                preg_match('/^wa_pax:(\d+)$/', $text, $match) === 1 => (int) $match[1],
                ctype_digit(trim($text)) => (int) trim($text),
                default => null,
            };
            if ($pax === null || $pax < 1 || $pax > 8) {
                WhatsAppFreeformSender::sendText($phone, 'Elija una opción de la lista o escriba un número de personas válido, entre 1 y 8.');

                return true;
            }
            $context['passenger_count'] = $pax;

            return $this->askWhoAttends($phone, $user, $conversation, $context);
        }

        if ($state === 'WA_BOOKING_SELECT') {
            // Pedido explícito del usuario: listar de una vez a los
            // conductores de su flota y las cooperativas disponibles (de su
            // red o públicas), para que elija directo a quién se la pide —
            // ver askWhoAttends() más abajo.
            if ($text === 'wa_select_public') {
                $context['dispatch_pool'] = 'public';

                return $this->confirm($phone, $conversation, $context);
            }
            if (preg_match('/^wa_select_driver:(\d+)$/', $text, $match) && in_array((int) $match[1], $context['select_driver_ids'] ?? [], true)) {
                $context['driver_user_id'] = (int) $match[1];
                $context['driver_name'] = $context['select_driver_names'][$match[1]] ?? null;
                $context['dispatch_pool'] = 'fleet';

                return $this->confirm($phone, $conversation, $context);
            }
            if (preg_match('/^wa_select_coop:(\d+)$/', $text, $match) && in_array((int) $match[1], $context['select_cooperative_ids'] ?? [], true)) {
                $context['cooperative_id'] = (int) $match[1];
                $context['cooperative_name'] = $context['select_cooperative_names'][$match[1]] ?? null;

                return $this->confirm($phone, $conversation, $context);
            }
            WhatsAppFreeformSender::sendText($phone, 'Elija una opción de la lista enviada.');

            return true;
        }

        if ($state === 'WA_BOOKING_CONFIRM') {
            if ($text !== 'wa_booking_confirm') {
                $this->clear($conversation);
                WhatsAppFreeformSender::sendText($phone, 'Solicitud descartada. Puede escribir “pedir carrera” cuando la necesite.');

                return true;
            }

            return $this->createRide($phone, $user, $conversation, $context);
        }

        return true;
    }

    private function askWhen(string $phone, ChatbotConversation $conversation): bool
    {
        $this->setState($conversation, 'WA_BOOKING_WHEN', []);
        WhatsAppFreeformSender::sendButtons($phone, '¡Listo! ¿Para cuándo necesita la carrera?', [
            ['id' => 'wa_when_now', 'title' => 'Ahora mismo'],
            ['id' => 'wa_when_scheduled', 'title' => 'Programar'],
        ]);

        return true;
    }

    /**
     * Pedido explícito del usuario ("cuando pida nuevamente mostrarle las
     * ubicaciones que ha solicitado para volverlas a repetir", y luego
     * "esto del chatbot podemos dejarlo en un solo mensaje que tenga los dos
     * botones"): antes se mandaban SIEMPRE dos mensajes seguidos (la lista
     * de recientes y, aparte, el botón nativo de ubicación) — ahora, si hay
     * recientes, van los dos en un solo mensaje de lista (las direcciones
     * más una fila "Nueva ubicación"); el botón nativo recién se manda si
     * elige esa fila (ver 'wa_recent_new' en handle()). Sin recientes
     * (cliente nuevo, o primera dirección de la conversación) sigue siendo
     * un único mensaje con el botón nativo, como siempre.
     */
    private function askLocation(string $phone, ChatbotConversation $conversation, string $state, array $context, string $message, ?User $user): bool
    {
        $field = $state === 'WA_BOOKING_ORIGIN' ? 'origin' : 'destination';

        $recent = $this->recentAddressOptions($user, $field);

        if ($recent) {
            $context['recent_'.$field.'_options'] = $recent;
            $this->setState($conversation, $state, $context);
            $rows = collect($recent)
                ->map(fn (array $point, int $i) => ['id' => "wa_recent_{$field}:{$i}", 'title' => $point['address']])
                ->push(['id' => 'wa_recent_new', 'title' => '📍 Nueva ubicación'])
                ->all();
            WhatsAppFreeformSender::sendList($phone, $message."\n\nPuede repetir una ubicación reciente o elegir \"Nueva ubicación\" para compartir un punto nuevo o escribir una dirección.", 'Ver opciones', $rows);

            return true;
        }

        $this->setState($conversation, $state, $context);
        $this->sendNativeLocationRequest($phone, $message);

        return true;
    }

    private function sendNativeLocationRequest(string $phone, string $message): void
    {
        $prompt = $message."\n\nToque “Enviar ubicación”, seleccione el punto exacto en el mapa y envíelo. También puede escribir una dirección completa.";

        if (! WhatsAppFreeformSender::sendLocationRequest($phone, $prompt)) {
            WhatsAppFreeformSender::sendText($phone, $message.' Abra el clip de WhatsApp, elija Ubicación y envíe el punto exacto. También puede escribir una dirección completa.');
        }
    }

    /**
     * @return array<int, array{lat: float, lng: float, address: string}>
     */
    private function recentAddressOptions(?User $user, string $field): array
    {
        if (! $user) {
            return [];
        }

        return RideRequest::query()
            ->where('client_user_id', $user->id)
            ->whereNotNull("{$field}_address")
            ->latest('id')
            ->limit(20)
            ->get(["{$field}_address as address", "{$field}_lat as lat", "{$field}_lng as lng"])
            ->unique('address')
            ->take(4)
            ->map(fn ($row) => ['address' => (string) $row->address, 'lat' => (float) $row->lat, 'lng' => (float) $row->lng])
            ->values()
            ->all();
    }

    /**
     * Guarda el punto (origen o destino) ya confirmado y avanza al
     * siguiente paso — extraído para reusarlo tanto cuando no hacía falta
     * confirmar (ubicación compartida, o una dirección repetida del
     * historial) como después de tocar "Sí, es correcto" en
     * WA_BOOKING_CONFIRM_POINT.
     */
    private function commitPoint(string $phone, ?User $user, ChatbotConversation $conversation, string $state, array $context, array $point): bool
    {
        $key = $state === 'WA_BOOKING_ORIGIN' ? 'origin' : 'destination';
        $context[$key] = $point;

        if ($key === 'origin') {
            return $this->askLocation($phone, $conversation, 'WA_BOOKING_DESTINATION', $context, '¿A dónde vamos? Comparta ubicación, dirección o coordenadas.', $user);
        }

        // Pedido explícito del usuario: botones de cantidad ("1, menor a 4,
        // menor a 7") en vez de texto libre, para que sea más rápido — el
        // valor real que se manda es el tope de cada rango (3 y 6), así el
        // conductor que se le ofrezca siempre tiene lugar de sobra para
        // cualquier cantidad real dentro de ese rango.
        $this->setState($conversation, 'WA_BOOKING_PAX', $context);
        WhatsAppFreeformSender::sendButtons($phone, '¿Cuántas personas son?', [
            ['id' => 'wa_pax:1', 'title' => '1 persona'],
            ['id' => 'wa_pax:3', 'title' => 'Menos de 4'],
            ['id' => 'wa_pax:6', 'title' => 'Menos de 7'],
        ]);

        return true;
    }

    /**
     * Punto de entrada desde WhatsAppLocationPickerController::store() —
     * el cliente eligió el punto en el mapa web (enlace de askLocation())
     * en vez de escribirlo o compartir ubicación por WhatsApp. Reusa el
     * mismo commitPoint() de siempre para que el mensaje de continuación
     * (pedir destino, o cuántas personas son) salga igual sea cual sea el
     * camino que se usó para resolver el punto.
     */
    public function commitLocationPickerPoint(ChatbotConversation $conversation, string $field, array $point): void
    {
        $state = $field === 'origin' ? 'WA_BOOKING_ORIGIN' : 'WA_BOOKING_DESTINATION';
        $context = $conversation->context ?? [];

        $this->commitPoint($conversation->phone, $conversation->user, $conversation, $state, $context, $point);
    }

    /** @param array{lat: float, lng: float}|null $biasPoint */
    private function resolvePoint(string $text, array $metadata, ?array $biasPoint): ?array
    {
        $location = $metadata['location'] ?? null;
        if (isset($location['lat'], $location['lng'])) {
            $lat = (float) $location['lat'];
            $lng = (float) $location['lng'];
            $address = $location['address'] ?: $location['name'];

            // Pedido explícito del usuario ("la ubicación que le llega al
            // conductor dice 'ubicación compartida'... pero no le dio el
            // detalle") — un pin suelto en el mapa de WhatsApp (a
            // diferencia de buscar un lugar con nombre) no trae ninguna
            // dirección, solo coordenadas; se resuelve con geocoding
            // inverso en vez de dejar el texto genérico.
            if (! $address) {
                $address = $this->geocoder->reverseGeocode($lat, $lng) ?? 'Ubicación compartida';
            }

            return ['lat' => $lat, 'lng' => $lng, 'address' => $address];
        }

        return $this->geocoder->resolve($text, $biasPoint['lat'] ?? null, $biasPoint['lng'] ?? null);
    }

    /**
     * Pedido explícito del usuario: "listarle las cooperativas disponibles
     * — desde la que está pública o de su flota — y sus conductores también
     * de su flota, y que el seleccione" — reemplaza al selector de
     * categorías (Mi flota/Cooperativas/Públicos) y a la búsqueda automática
     * y silenciosa de la cooperativa más cercana: ahora siempre se listan
     * las opciones reales (conductores de su flota + cooperativas de su red
     * o públicas) para que el cliente elija directo a quién se la pide,
     * mismo criterio que "Elige tu conductor" del lado web
     * (RideRequestController::create()).
     */
    private function askWhoAttends(string $phone, ?User $user, ChatbotConversation $conversation, array $context): bool
    {
        $origin = $context['origin'];

        $fleetDrivers = $user
            ? FleetMember::query()
                ->whereHas('fleet', fn ($query) => $query->where('owner_user_id', $user->id))
                ->whereNull('left_at')
                ->where('requests_disabled', false)
                ->with('driver')
                ->get()
                ->pluck('driver')
                ->filter()
                ->take(5)
            : collect();

        // Mismo criterio que RideRequestController::create() del lado web:
        // cooperativas que el cliente ya agregó a su red, más las que un
        // admin marcó como públicas — nunca una cooperativa ajena y privada.
        $addedCooperativeIds = $user
            ? ClientCooperative::query()->where('client_user_id', $user->id)->pluck('cooperative_id')
            : collect();
        $cooperatives = Cooperative::query()
            ->where('status', 'approved')
            ->whereNull('suspended_at')
            ->whereNotNull('stand_lat')
            ->where(fn ($query) => $query->whereIn('id', $addedCooperativeIds)->orWhere('is_public', true))
            ->get()
            ->filter(fn (Cooperative $coop) => $coop->max_request_distance_km === null
                || Haversine::distanceKm((float) $origin['lat'], (float) $origin['lng'], (float) $coop->stand_lat, (float) $coop->stand_lng) <= $coop->max_request_distance_km)
            ->sortBy(fn (Cooperative $coop) => Haversine::distanceKm((float) $origin['lat'], (float) $origin['lng'], (float) $coop->stand_lat, (float) $coop->stand_lng))
            ->take(4)
            ->values();

        if ($fleetDrivers->isEmpty() && $cooperatives->isEmpty()) {
            $this->clear($conversation);
            // Mismo mensaje que la pantalla web ("Elige tu conductor") para
            // este mismo caso vacío — ver Ride/Request.vue.
            WhatsAppFreeformSender::sendText($phone, 'Todavía no tiene conductores agregados a su flota ni cooperativas en su red. Puede agregarlos desde Arka01, o escribir "pedir carrera" de nuevo para elegir del directorio público.');

            return true;
        }

        $rows = [];
        $driverNames = [];
        foreach ($fleetDrivers as $driver) {
            $rows[] = ['id' => 'wa_select_driver:'.$driver->id, 'title' => '🚗 '.Str::limit($driver->full_name, 22, '')];
            $driverNames[$driver->id] = $driver->full_name;
        }
        $cooperativeNames = [];
        foreach ($cooperatives as $cooperative) {
            $rows[] = ['id' => 'wa_select_coop:'.$cooperative->id, 'title' => '🏢 '.Str::limit($cooperative->name, 22, '')];
            $cooperativeNames[$cooperative->id] = $cooperative->name;
        }
        $rows[] = ['id' => 'wa_select_public', 'title' => '🌐 Directorio público'];

        $context['select_driver_ids'] = $fleetDrivers->pluck('id')->all();
        $context['select_driver_names'] = $driverNames;
        $context['select_cooperative_ids'] = $cooperatives->pluck('id')->all();
        $context['select_cooperative_names'] = $cooperativeNames;
        $this->setState($conversation, 'WA_BOOKING_SELECT', $context);
        WhatsAppFreeformSender::sendList($phone, '¿Quién le atiende esta carrera?', 'Elegir', $rows);

        return true;
    }

    private function confirm(string $phone, ChatbotConversation $conversation, array $context): bool
    {
        $this->setState($conversation, 'WA_BOOKING_CONFIRM', $context);
        $when = ! empty($context['is_scheduled']) ? Carbon::parse($context['scheduled_at'])->format('d/m/Y H:i') : 'Ahora mismo';

        // Pedido explícito del usuario: "a quién se la pedís" según lo que
        // haya elegido en askWhoAttends() — un conductor puntual, una
        // cooperativa, o el directorio público si no tiene ninguna opción
        // propia cerca.
        $who = match (true) {
            ! empty($context['driver_name']) => "🚗 Conductor: {$context['driver_name']}",
            ! empty($context['cooperative_name']) => "🏢 Cooperativa: {$context['cooperative_name']}",
            ($context['dispatch_pool'] ?? null) === 'public' => '🌐 Directorio público',
            default => '🚗 Su flota de confianza',
        };

        // Pedido explícito del usuario: "que no coloques los precios de la
        // carrera, dejemos que cuando se asigne el conductor se maneje el
        // precio" — antes se mostraba un costo aproximado acá para la
        // cooperativa más cercana; ahora el precio recién se ve una vez que
        // un conductor de verdad toma la solicitud.
        $message = "*Confirme su solicitud*\n\n{$who}\n🟢 Origen: {$context['origin']['address']}\n🔴 Destino: {$context['destination']['address']}\n🕐 {$when}\n\nEl precio se calcula según la tarifa de quien la reciba y se lo confirmamos apenas la acepte.";

        WhatsAppFreeformSender::sendButtons($phone, $message, [
            ['id' => 'wa_booking_confirm', 'title' => 'Confirmar'],
            ['id' => 'wa_booking_cancel', 'title' => 'Cancelar'],
        ]);

        return true;
    }

    private function createRide(string $phone, User $user, ChatbotConversation $conversation, array $context): bool
    {
        $payload = [
            'fleet_id' => $user->fleets()->value('id'),
            'cooperative_id' => $context['cooperative_id'] ?? null,
            'driver_user_id' => $context['driver_user_id'] ?? null,
            'dispatch_pool' => $context['dispatch_pool'] ?? null,
            'origin_lat' => $context['origin']['lat'], 'origin_lng' => $context['origin']['lng'], 'origin_address' => $context['origin']['address'],
            'destination_lat' => $context['destination']['lat'], 'destination_lng' => $context['destination']['lng'], 'destination_address' => $context['destination']['address'],
            'is_scheduled' => ! empty($context['is_scheduled']),
            'passenger_count' => $context['passenger_count'] ?? 1, 'needs_trunk' => false, 'payment_method' => 'efectivo',
            // Ver el comentario en handle(): un conductor pidiendo una
            // carrera con su propio número por WhatsApp es la única cuenta
            // no-cliente que llega hasta acá — este flag es lo que
            // RideRequestController::store() exige para permitirlo.
            'whatsapp_guest_booking' => ! $user->isClient(),
        ];
        if ($payload['is_scheduled']) {
            $date = Carbon::parse($context['scheduled_at']);
            $payload['scheduled_date'] = $date->format('Y-m-d');
            $payload['scheduled_time'] = $date->format('H:i');
        }
        // Pedido explícito del usuario: "lo que te coloque por origen y
        // destino deberas pasarlo por la api de google para determinar los
        // km" — si ya se calculó, se manda el km real en vez de dejar que el
        // servidor recalcule con línea recta (RideRequestController ya
        // confía en este campo si está dentro de un rango razonable contra
        // la línea recta, ver su validación de route_distance_km).
        if (! empty($context['route_distance_km'])) {
            $payload['route_distance_km'] = $context['route_distance_km'];
        }

        $request = Request::create('/flota/solicitar', 'POST', $payload);
        $request->setUserResolver(fn () => $user);
        try {
            app(RideRequestController::class)->store($request);
            $rideRequest = RideRequest::query()->where('client_user_id', $user->id)->latest('id')->firstOrFail();
            $this->clear($conversation);
            // Bug real reportado por el usuario (con captura: "el valor es
            // $0.00" después de confirmar) — el campo del modelo es
            // `current_offered_price`, `offered_price` no existe como
            // columna (Eloquent devuelve null en silencio en vez de un
            // error, por eso pasó desapercibido).
            // Pedido explícito del usuario: botón para cancelar la solicitud
            // apenas queda creada, sin tener que escribirle nada al bot
            // primero — reusa el mismo id "wa_pending_cancel" que ya maneja
            // WhatsAppPendingRequestHandler para una solicitud sin aceptar.
            WhatsAppFreeformSender::sendButtons(
                $phone,
                '✅ Solicitud #'.$rideRequest->id.' creada por $'.number_format((float) $rideRequest->current_offered_price, 2).'. Le avisaremos por aquí y en Arka01 cuando un conductor acepte.',
                [['id' => 'wa_pending_cancel', 'title' => 'Cancelar solicitud']],
            );
            // Pedido explícito del usuario: "si no ha recibido ninguna
            // repuesta en unos 30 segundos y la solicitud sigue viva que
            // indique que se esta buscando un conductor".
            NotifyWhatsAppStillSearchingForDriver::dispatch($rideRequest->id)->delay(now()->addSeconds(30));
            $this->offerFullRegistrationIfFirstGuestRide($phone, $user);
        } catch (ValidationException $e) {
            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, 'No pudimos crear la solicitud: '.collect($e->errors())->flatten()->first());
        } catch (Throwable $e) {
            report($e);
            WhatsAppFreeformSender::sendText($phone, 'No pudimos crear la solicitud. Sus datos no se perdieron; intente confirmar nuevamente o use Arka01.');
        }

        return true;
    }

    /**
     * Pedido explícito del usuario: "si realmente no se encontro que la
     * misma plataforma indique y le mande un boton que diga pedir
     * nuevamente y que intente nuevamente con esos mismo parametros" —
     * vuelve a armar exactamente la misma solicitud (mismo origen, destino,
     * cantidad de pasajeros y a quién iba dirigida) sin volver a preguntar
     * nada, ya que el cliente solo quiere reintentar, no cambiar nada.
     */
    private function retryExpiredRequest(string $phone, ?User $user, int $rideRequestId, ChatbotConversation $conversation): bool
    {
        $original = $user ? RideRequest::query()->where('id', $rideRequestId)->where('client_user_id', $user->id)->first() : null;

        if (! $user || ! $original) {
            WhatsAppFreeformSender::sendText($phone, 'No pudimos encontrar esa solicitud. Escriba "pedir carrera" para empezar de nuevo.');

            return true;
        }

        $context = [
            'origin' => ['lat' => (float) $original->origin_lat, 'lng' => (float) $original->origin_lng, 'address' => $original->origin_address],
            'destination' => ['lat' => (float) $original->destination_lat, 'lng' => (float) $original->destination_lng, 'address' => $original->destination_address],
            'passenger_count' => $original->passenger_count,
            'is_scheduled' => false,
            'dispatch_pool' => $original->dispatch_pool,
            'cooperative_id' => $original->cooperative_id,
            'driver_user_id' => $original->price_reference_driver_user_id,
        ];

        return $this->createRide($phone, $user, $conversation, $context);
    }

    /**
     * Pedido explícito del usuario ("deberiamos verificar que si no tiene
     * cuenta preguntarle si quiere registrarse para luego sus solicitudes
     * sean mas rapido") — la cuenta YA se crea sola en la primera reserva
     * (ver WA_BOOKING_NAME_CONFIRM más arriba), pero queda con una
     * contraseña al azar que nunca vio (`password_set_at` sigue en null,
     * a diferencia del registro real por Auth\RegisteredUserController) —
     * no puede entrar a Arka01 con ella. Se lo invita UNA sola vez, justo
     * después de su primera carrera (cuando ya vio que el servicio
     * funciona, el mejor momento) — nunca de nuevo en las siguientes.
     */
    private function offerFullRegistrationIfFirstGuestRide(string $phone, User $user): void
    {
        if ($user->password_set_at !== null) {
            return;
        }

        if (RideRequest::query()->where('client_user_id', $user->id)->count() > 1) {
            return;
        }

        // Link firmado (mismo patrón ya probado que
        // Auth\SessionTakeoverController::lock()): no tiene contraseña real
        // para entrar por el login normal ni un correo real para "olvidé mi
        // contraseña" — este link lo deja entrar sin ninguna de las dos,
        // directo a completar su correo y contraseña de verdad. Válido 24h
        // (alcanza de sobra para que lo abra desde el mismo chat).
        $link = URL::temporarySignedRoute('guest-account.complete-registration', now()->addHours(24), ['user' => $user->id]);
        WhatsAppFreeformSender::sendText(
            $phone,
            "💡 Termine de registrarse en Arka01 con su correo y contraseña — así puede entrar a la app para ver sus carreras y pedir más rápido la próxima vez:\n{$link}"
        );
    }

    private function setState(ChatbotConversation $conversation, string $state, array $context): void
    {
        $conversation->update(['pending_intent' => $state, 'context' => $context, 'unresolved_attempts' => 0, 'last_message_at' => now()]);
    }

    private function clear(ChatbotConversation $conversation): void
    {
        $conversation->update(['pending_intent' => null, 'context' => null, 'last_message_at' => now()]);
    }
}
