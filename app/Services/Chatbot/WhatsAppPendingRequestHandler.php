<?php

namespace App\Services\Chatbot;

use App\Http\Controllers\RideRequestController;
use App\Models\ChatbotConversation;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Http\Request;

/**
 * Pedido explícito del usuario, con captura real de WhatsApp: pidió una
 * carrera, ningún conductor la aceptó, y al escribir de nuevo ("Que paso?")
 * el bot no reconoció que tenía una solicitud pendiente — le mandó el menú
 * genérico ("no logré identificar lo que necesitas"), que no contestaba
 * nada de lo que preguntaba. "Cuando hablamos que se manejaran estados y si
 * no hay conductores el cliente tiene que recibir el feedback... y que le
 * muestre los botones para cancelar solicitud o permanecer."
 *
 * Mientras el cliente tenga una solicitud SIN aceptar todavía (pending,
 * negotiating o en lista de espera), cualquier mensaje que escriba — salvo
 * que ya esté a mitad de pedir una carrera nueva — cae acá primero: se le
 * cuenta el estado real y se le ofrecen las 2 salidas (seguir esperando o
 * cancelar), en vez de caer al menú genérico que no tiene nada que ver con
 * lo que está preguntando.
 */
class WhatsAppPendingRequestHandler
{
    private const STATUS_LABEL = [
        'pending' => 'buscando un conductor',
        'negotiating' => 'negociando el precio con un conductor',
        'waiting' => 'en lista de espera (todos los conductores están ocupados ahora mismo)',
    ];

    public function handle(string $phone, ?User $user, string $text, ChatbotConversation $conversation): bool
    {
        if (! $user) {
            return false;
        }

        // Ya está a mitad de pedir una carrera nueva (o de aceptar el aviso
        // de privacidad) — no interferir, WhatsAppRideBookingHandler ya
        // sabe manejar ese flujo paso a paso.
        $state = (string) $conversation->pending_intent;
        if (str_starts_with($state, 'WA_BOOKING_') || $state === 'WA_PRIVACY_CONSENT') {
            return false;
        }

        $rideRequest = RideRequest::query()
            ->where('client_user_id', $user->id)
            ->whereIn('status', ['pending', 'negotiating', 'waiting'])
            ->latest('id')
            ->first();

        if (! $rideRequest) {
            return false;
        }

        if ($text === 'wa_pending_cancel') {
            $request = Request::create("/flota/solicitudes/{$rideRequest->id}/cancelar", 'POST');
            $request->setUserResolver(fn () => $user);
            app(RideRequestController::class)->cancel($request, $rideRequest);

            WhatsAppFreeformSender::sendText($phone, 'Listo, cancelamos su solicitud #'.$rideRequest->id.'.');

            return true;
        }

        if ($text === 'wa_pending_wait') {
            WhatsAppFreeformSender::sendText($phone, 'Perfecto, seguimos buscando — le avisamos por acá apenas un conductor acepte.');

            return true;
        }

        $statusLine = self::STATUS_LABEL[$rideRequest->status] ?? 'en proceso';
        WhatsAppFreeformSender::sendButtons(
            $phone,
            "🔎 Su solicitud #{$rideRequest->id} sigue {$statusLine}. Le avisamos por acá apenas un conductor acepte.",
            [
                ['id' => 'wa_pending_wait', 'title' => 'Seguir esperando'],
                ['id' => 'wa_pending_cancel', 'title' => 'Cancelar solicitud'],
            ]
        );

        return true;
    }
}
