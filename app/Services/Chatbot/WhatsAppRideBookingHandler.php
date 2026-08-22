<?php

namespace App\Services\Chatbot;

use App\Http\Controllers\RideRequestController;
use App\Models\ChatbotConversation;
use App\Models\Fleet;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Flujo conversacional de solicitud de transporte; usa el mismo controlador que la web. */
class WhatsAppRideBookingHandler
{
    public function __construct(private readonly GoogleGeocodingService $geocoder) {}

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

        if ($user && ! $user->isClient()) {
            WhatsAppFreeformSender::sendText($phone, 'Esta opción está disponible para cuentas de cliente. Su cuenta conserva el rol actual en Arka01.');

            return true;
        }

        if ($startsBooking) {
            if (! $user?->whatsapp_privacy_accepted_at) {
                $notice = WhatsAppSetting::current()->privacy_notice_text
                    ?: 'Usaremos su número, ubicaciones y datos del viaje para gestionar el transporte y la seguridad del servicio.';
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

            return $this->askLocation($phone, $conversation, 'WA_BOOKING_ORIGIN', $context, '¿Desde dónde le recogemos? Comparta su ubicación o escriba una dirección completa o coordenadas.');
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

            return $this->askLocation($phone, $conversation, 'WA_BOOKING_ORIGIN', $context, '¿Desde dónde le recogemos? Comparta ubicación, dirección o coordenadas.');
        }

        if (in_array($state, ['WA_BOOKING_ORIGIN', 'WA_BOOKING_DESTINATION'], true)) {
            $point = $this->resolvePoint($text, $metadata);
            if (! $point) {
                WhatsAppFreeformSender::sendText($phone, 'No pude ubicar ese punto. Comparta la ubicación desde WhatsApp o escriba calle, sector y ciudad.');

                return true;
            }
            $key = $state === 'WA_BOOKING_ORIGIN' ? 'origin' : 'destination';
            $context[$key] = $point;
            if ($key === 'origin') {
                return $this->askLocation($phone, $conversation, 'WA_BOOKING_DESTINATION', $context, '¿A dónde vamos? Comparta ubicación, dirección o coordenadas.');
            }
            $this->setState($conversation, 'WA_BOOKING_CATEGORY', $context);
            WhatsAppFreeformSender::sendButtons($phone, '¿Quién desea que atienda la carrera?', [
                ['id' => 'wa_pool_fleet', 'title' => 'Mi flota'],
                ['id' => 'wa_pool_coop', 'title' => 'Cooperativas'],
                ['id' => 'wa_pool_public', 'title' => 'Públicos'],
            ]);

            return true;
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

    private function askLocation(string $phone, ChatbotConversation $conversation, string $state, array $context, string $message): bool
    {
        $this->setState($conversation, $state, $context);
        WhatsAppFreeformSender::sendText($phone, $message);

        return true;
    }

    private function resolvePoint(string $text, array $metadata): ?array
    {
        $location = $metadata['location'] ?? null;
        if (isset($location['lat'], $location['lng'])) {
            return ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng'], 'address' => $location['address'] ?: ($location['name'] ?: 'Ubicación compartida')];
        }

        return $this->geocoder->resolve($text);
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

    private function confirm(string $phone, ChatbotConversation $conversation, array $context): bool
    {
        $this->setState($conversation, 'WA_BOOKING_CONFIRM', $context);
        $when = ! empty($context['is_scheduled']) ? Carbon::parse($context['scheduled_at'])->format('d/m/Y H:i') : 'Ahora mismo';
        WhatsAppFreeformSender::sendButtons($phone, "Confirme su solicitud:\n🟢 {$context['origin']['address']}\n🔴 {$context['destination']['address']}\n🕐 {$when}", [
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
            'passenger_count' => 1, 'needs_trunk' => false, 'payment_method' => 'efectivo',
        ];
        if ($payload['is_scheduled']) {
            $date = Carbon::parse($context['scheduled_at']);
            $payload['scheduled_date'] = $date->format('Y-m-d');
            $payload['scheduled_time'] = $date->format('H:i');
        }
        $request = Request::create('/flota/solicitar', 'POST', $payload);
        $request->setUserResolver(fn () => $user);
        try {
            app(RideRequestController::class)->store($request);
            $rideRequest = RideRequest::query()->where('client_user_id', $user->id)->latest('id')->firstOrFail();
            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, '✅ Solicitud #'.$rideRequest->id.' creada por $'.number_format((float) $rideRequest->offered_price, 2).'. Le avisaremos por aquí y en Arka01 cuando un conductor acepte.');
        } catch (ValidationException $e) {
            $this->clear($conversation);
            WhatsAppFreeformSender::sendText($phone, 'No pudimos crear la solicitud: '.collect($e->errors())->flatten()->first());
        } catch (Throwable $e) {
            report($e);
            WhatsAppFreeformSender::sendText($phone, 'No pudimos crear la solicitud. Sus datos no se perdieron; intente confirmar nuevamente o use Arka01.');
        }

        return true;
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
