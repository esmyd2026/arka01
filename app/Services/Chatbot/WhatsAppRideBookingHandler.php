<?php

namespace App\Services\Chatbot;

use App\Http\Controllers\RideRequestController;
use App\Models\ChatbotConversation;
use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\GoogleGeocodingService;
use App\Services\GoogleRoutesService;
use App\Services\Haversine;
use App\Services\PriceCalculator;
use App\Services\RideDispatchCandidates;
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
        private readonly GoogleRoutesService $routes,
    ) {}

    public function handle(string $phone, ?User $user, string $text, array $metadata, ChatbotConversation $conversation): bool
    {
        $state = $conversation->pending_intent;
        $startsBooking = in_array(MessageNormalizer::normalize($text), [
            'pedir carrera', 'solicitar carrera', 'pedir un viaje', 'solicitar viaje', 'viaje',
        ], true);

        if (! $startsBooking && ! str_starts_with((string) $state, 'WA_BOOKING_') && $state !== 'WA_PRIVACY_CONSENT') {
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
            $pax = (int) trim($text);
            if (! ctype_digit(trim($text)) || $pax < 1 || $pax > 8) {
                WhatsAppFreeformSender::sendText($phone, 'Escriba un número de personas válido, entre 1 y 8.');

                return true;
            }
            $context['passenger_count'] = $pax;

            // Pedido explícito del usuario: "si ese numero ya esta
            // registrado que busque si tiene flota y si no tiene indicarle
            // que se buscara un conductor de las cooperativas mas cercanas".
            // Con flota propia (con conductores) sigue el selector de
            // siempre; sin flota, se salta ese paso y busca solo.
            if ($user && $user->fleets()->whereHas('activeMembers')->exists()) {
                $this->setState($conversation, 'WA_BOOKING_CATEGORY', $context);
                WhatsAppFreeformSender::sendButtons($phone, '¿Quién desea que atienda la carrera?', [
                    ['id' => 'wa_pool_fleet', 'title' => 'Mi flota'],
                    ['id' => 'wa_pool_coop', 'title' => 'Cooperativas'],
                    ['id' => 'wa_pool_public', 'title' => 'Públicos'],
                ]);

                return true;
            }

            return $this->offerNearestCooperative($phone, $conversation, $context);
        }

        if ($state === 'WA_BOOKING_CATEGORY') {
            if ($text === 'wa_pool_coop') {
                return $this->askCooperative($phone, $user, $conversation, $context);
            }
            $context['dispatch_pool'] = $text === 'wa_pool_public' ? 'public' : 'fleet';

            return $this->confirm($phone, $conversation, $context);
        }

        if ($state === 'WA_BOOKING_COOPERATIVE') {
            if (! preg_match('/^wa_coop:(\d+)$/', $text, $match) || ! in_array((int) $match[1], $context['cooperative_ids'] ?? [], true)) {
                WhatsAppFreeformSender::sendText($phone, 'Seleccione una cooperativa de la lista enviada.');

                return true;
            }
            $context['cooperative_id'] = (int) $match[1];

            return $this->confirm($phone, $conversation, $context);
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
     * ubicaciones que ha solicitado para volverlas a repetir") — si el
     * cliente ya tiene carreras anteriores, ofrece sus últimas direcciones
     * (distintas entre sí) como lista real de WhatsApp. En todos los casos
     * se manda después el botón nativo de ubicación: abre el mapa dentro de
     * WhatsApp y devuelve las coordenadas sin sacar al cliente del chat.
     */
    private function askLocation(string $phone, ChatbotConversation $conversation, string $state, array $context, string $message, ?User $user): bool
    {
        $field = $state === 'WA_BOOKING_ORIGIN' ? 'origin' : 'destination';

        $recent = $this->recentAddressOptions($user, $field);
        $this->setState($conversation, $state, $context);

        if ($recent) {
            $context['recent_'.$field.'_options'] = $recent;
            $this->setState($conversation, $state, $context);
            $rows = collect($recent)
                ->map(fn (array $point, int $i) => ['id' => "wa_recent_{$field}:{$i}", 'title' => $point['address']])
                ->all();
            WhatsAppFreeformSender::sendList($phone, 'Puede repetir una ubicación reciente o enviar un punto nuevo con el botón del siguiente mensaje.', 'Ver recientes', $rows);
        }

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

        // Pedido explícito del usuario: preguntar cuántas personas son
        // (antes quedaba fijo en 1, ver createRide() más abajo).
        $this->setState($conversation, 'WA_BOOKING_PAX', $context);
        WhatsAppFreeformSender::sendText($phone, '¿Cuántas personas son?');

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

    private function askCooperative(string $phone, User $user, ChatbotConversation $conversation, array $context): bool
    {
        $origin = $context['origin'];
        $cooperatives = $user->clientCooperativeLinks()->with('cooperative.activeDriverMemberships')->get()
            ->pluck('cooperative')->filter(fn ($coop) => $coop?->isApproved() && $coop->stand_lat !== null)
            ->sortBy(fn ($coop) => Haversine::distanceKm((float) $origin['lat'], (float) $origin['lng'], (float) $coop->stand_lat, (float) $coop->stand_lng))
            ->take(3)->values();
        if ($cooperatives->isEmpty()) {
            WhatsAppFreeformSender::sendText($phone, 'No tiene cooperativas habilitadas cerca. Elija Mi flota o Públicos.');

            return true;
        }
        $context['cooperative_ids'] = $cooperatives->pluck('id')->all();
        $this->setState($conversation, 'WA_BOOKING_COOPERATIVE', $context);
        WhatsAppFreeformSender::sendButtons($phone, 'Cooperativas de su red más cercanas al origen:', $cooperatives->map(fn ($coop) => [
            'id' => 'wa_coop:'.$coop->id,
            'title' => Str::limit($coop->name, 20, ''),
        ])->all());

        return true;
    }

    /**
     * Pedido explícito del usuario: si el cliente no tiene flota propia,
     * buscar solo entre las cooperativas APROBADAS más cercanas al origen
     * (sin el filtro de "ya vinculada" que sí tiene askCooperative(), pensado
     * para el otro caso — cliente CON cooperativas propias eligiendo entre
     * ellas) — y mostrarle el costo aproximado con km reales de Google antes
     * de confirmar. Prueba cada cooperativa en orden hasta encontrar una con
     * al menos un conductor elegible de verdad (RideDispatchCandidates ya
     * filtra disponibilidad, capacidad, alcance).
     */
    private function offerNearestCooperative(string $phone, ChatbotConversation $conversation, array $context): bool
    {
        $origin = $context['origin'];
        $destination = $context['destination'];
        $passengerCount = (int) ($context['passenger_count'] ?? 1);

        $cooperatives = Cooperative::query()
            ->where('status', 'approved')
            ->whereNull('suspended_at')
            ->whereNotNull('stand_lat')
            ->get()
            ->sortBy(fn (Cooperative $coop) => Haversine::distanceKm((float) $origin['lat'], (float) $origin['lng'], (float) $coop->stand_lat, (float) $coop->stand_lng))
            ->take(5);

        foreach ($cooperatives as $cooperative) {
            $candidateIds = RideDispatchCandidates::forCooperative($cooperative, (float) $origin['lat'], (float) $origin['lng'], $passengerCount);
            if (empty($candidateIds)) {
                continue;
            }

            // Sin la clave de Google configurada, route() devuelve null — se
            // sigue mostrando un aproximado con línea recta en vez de
            // trabarse (mismo respaldo que ya usa RideRequestController).
            $route = $this->routes->route((float) $origin['lat'], (float) $origin['lng'], (float) $destination['lat'], (float) $destination['lng']);
            $distanceKm = $route['distance_km'] ?? null;
            $distanceKm ??= Haversine::distanceKm((float) $origin['lat'], (float) $origin['lng'], (float) $destination['lat'], (float) $destination['lng']);

            $topDriver = DriverProfile::query()->where('user_id', $candidateIds[0])->first();
            $price = $topDriver ? PriceCalculator::suggestedPrice(
                $distanceKm,
                (float) $topDriver->rate_per_km,
                driverMinimumFare: $topDriver->minimum_fare !== null ? (float) $topDriver->minimum_fare : null,
            ) : null;

            $context['cooperative_id'] = $cooperative->id;
            $context['cooperative_name'] = $cooperative->name;
            // RideRequestController::store() solo acepta un `cooperative_id`
            // que el cliente ya tenga vinculado (ClientCooperative) — acá se
            // vincula recién al confirmar (createRide()), nunca antes: no
            // tiene sentido sumarla a su red si al final dice "No".
            $context['auto_link_cooperative'] = true;
            if (! empty($route['distance_km'])) {
                $context['route_distance_km'] = $route['distance_km'];
            }
            if ($price) {
                $context['estimated_price'] = $price['total'];
            }

            return $this->confirm($phone, $conversation, $context);
        }

        $this->clear($conversation);
        WhatsAppFreeformSender::sendText($phone, 'No encontramos conductores disponibles en las cooperativas cercanas por ahora. Intente más tarde, o arme su flota de confianza desde Arka01.');

        return true;
    }

    private function confirm(string $phone, ChatbotConversation $conversation, array $context): bool
    {
        $this->setState($conversation, 'WA_BOOKING_CONFIRM', $context);
        $when = ! empty($context['is_scheduled']) ? Carbon::parse($context['scheduled_at'])->format('d/m/Y H:i') : 'Ahora mismo';
        $message = "Confirme su solicitud:\n🟢 {$context['origin']['address']}\n🔴 {$context['destination']['address']}\n🕐 {$when}";

        // Pedido explícito del usuario: "que le diga el costo aproximado de
        // la carrera" cuando se buscó sola una cooperativa cercana — en el
        // flujo de siempre (flota propia/elegida a mano) no se muestra
        // precio acá, se sigue viendo recién al aceptar un conductor.
        if (! empty($context['cooperative_name'])) {
            $message = "Buscaremos un conductor en {$context['cooperative_name']}, la cooperativa más cercana a su origen.\n\n{$message}";
            if (! empty($context['estimated_price'])) {
                $message .= "\n💵 Costo aproximado: \$".number_format((float) $context['estimated_price'], 2);
            }
        }

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
        // km" — si ya se calculó (siempre que se ofreció una cooperativa
        // cercana automáticamente), se manda el km real en vez de dejar que
        // el servidor recalcule con línea recta (RideRequestController ya
        // confía en este campo si está dentro de un rango razonable contra
        // la línea recta, ver su validación de route_distance_km).
        if (! empty($context['route_distance_km'])) {
            $payload['route_distance_km'] = $context['route_distance_km'];
        }

        // RideRequestController::store() solo acepta una cooperativa que el
        // cliente ya tenga en su red (ClientCooperative) — la búsqueda
        // automática de la más cercana (offerNearestCooperative()) recién
        // vincula acá, justo antes de mandar la solicitud, nunca antes de
        // que confirme con "Sí".
        if (! empty($context['auto_link_cooperative']) && ! empty($context['cooperative_id'])) {
            ClientCooperative::query()->firstOrCreate([
                'client_user_id' => $user->id,
                'cooperative_id' => $context['cooperative_id'],
            ]);
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
            WhatsAppFreeformSender::sendText($phone, '✅ Solicitud #'.$rideRequest->id.' creada por $'.number_format((float) $rideRequest->current_offered_price, 2).'. Le avisaremos por aquí y en Arka01 cuando un conductor acepte.');
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
