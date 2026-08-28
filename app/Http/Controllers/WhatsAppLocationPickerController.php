<?php

namespace App\Http\Controllers;

use App\Models\ChatbotConversation;
use App\Services\Chatbot\WhatsAppRideBookingHandler;
use App\Services\GoogleGeocodingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido explícito del usuario: el bot de WhatsApp pide la dirección
 * ESCRITA para origen y destino, pero además manda un enlace para abrir
 * este mapa — buscar el lugar ahí y mandar coordenadas exactas es más
 * confiable que confiar solo en que el texto libre geocodifique bien (ver
 * WhatsAppRideBookingHandler::resolvePoint()).
 *
 * Público a propósito: quien abre este enlace viene desde WhatsApp, nunca
 * tiene sesión iniciada en la app — la única protección es la firma
 * temporal de la URL (mismo patrón que
 * Auth\GuestAccountController::completeRegistration()), atada a la
 * conversación del bot, no a un usuario.
 */
class WhatsAppLocationPickerController extends Controller
{
    public function __construct(
        private readonly WhatsAppRideBookingHandler $bookingHandler,
        private readonly GoogleGeocodingService $geocoder,
    ) {}

    public function show(ChatbotConversation $conversation, string $step): Response
    {
        return Inertia::render('WhatsApp/LocationPicker', [
            'step' => $step,
            'valid' => $this->matchesExpectedStep($conversation, $step),
            'center' => $this->centerFor($conversation, $step),
            'submitted' => false,
        ]);
    }

    public function store(Request $request, ChatbotConversation $conversation, string $step): Response
    {
        if (! $this->matchesExpectedStep($conversation, $step)) {
            throw ValidationException::withMessages([
                'step' => 'Este enlace ya no está activo. Vuelva a WhatsApp y escriba "pedir carrera" para empezar de nuevo.',
            ]);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            // El mapa ya manda la dirección resuelta del lado del navegador
            // (Nominatim) — este reverse-geocode del lado del servidor es
            // solo un respaldo si por lo que sea llegó vacío.
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $address = $validated['address']
            ?: $this->geocoder->reverseGeocode($validated['lat'], $validated['lng'])
            ?: 'Ubicación seleccionada en el mapa';

        $this->bookingHandler->commitLocationPickerPoint($conversation, $step, [
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'address' => $address,
        ]);

        return Inertia::render('WhatsApp/LocationPicker', [
            'step' => $step,
            'valid' => true,
            'center' => null,
            'submitted' => true,
        ]);
    }

    private function matchesExpectedStep(ChatbotConversation $conversation, string $step): bool
    {
        $expected = $step === 'origin' ? 'WA_BOOKING_ORIGIN' : 'WA_BOOKING_DESTINATION';

        return $conversation->pending_intent === $expected;
    }

    /** @return array{lat: float, lng: float}|null */
    private function centerFor(ChatbotConversation $conversation, string $step): ?array
    {
        $context = $conversation->context ?? [];

        // Para el destino, el origen recién confirmado es mejor pista que la
        // ciudad de registro (mismo criterio que resolvePoint() en el bot).
        if ($step === 'destination' && isset($context['origin']['lat'], $context['origin']['lng'])) {
            return ['lat' => (float) $context['origin']['lat'], 'lng' => (float) $context['origin']['lng']];
        }

        $city = $conversation->user?->city;

        return $city ? ['lat' => (float) $city->lat, 'lng' => (float) $city->lng] : null;
    }
}
