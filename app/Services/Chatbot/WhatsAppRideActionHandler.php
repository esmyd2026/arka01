<?php

namespace App\Services\Chatbot;

use App\Events\RideMessageSent;
use App\Http\Controllers\RideController;
use App\Http\Controllers\RideRequestController;
use App\Models\ChatbotConversation;
use App\Models\Ride;
use App\Models\RideMessage;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\WhatsAppFreeformSender;
use App\Services\WhatsAppRideAccess;
use Illuminate\Http\Request;
use Throwable;

class WhatsAppRideActionHandler
{
    public function handle(User $user, string $rawText, ChatbotConversation $conversation): bool
    {
        if (preg_match('/^ride_(accept|reject):(\d+)$/', $rawText, $matches)) {
            return $this->respondToOffer($user, $matches[1], (int) $matches[2]);
        }

        if (preg_match('/^ride_cancel:(\d+)$/', $rawText, $matches)) {
            return $this->cancelRide($user, (int) $matches[1]);
        }

        if (preg_match('/^ride_chat:(\d+)$/', $rawText, $matches)) {
            $canUseBridge = $user->isDriver()
                ? WhatsAppRideAccess::driverCanOperate($user)
                : ($user->isClient() && WhatsAppRideAccess::clientCanBook());
            if (! $canUseBridge) {
                WhatsAppFreeformSender::sendText($user->phone, 'Esta opción está deshabilitada. Puede continuar la conversación desde la app.');

                return true;
            }

            $ride = Ride::query()->find((int) $matches[1]);
            if (! $ride || ! $ride->chatIsOpen() || ! in_array($user->id, [$ride->client_user_id, $ride->driver_user_id], true)) {
                WhatsAppFreeformSender::sendText($user->phone, 'El chat de esa carrera ya no está disponible.');

                return true;
            }

            $conversation->update(['pending_intent' => 'RIDE_CHAT', 'context' => ['ride_id' => $ride->id]]);
            WhatsAppFreeformSender::sendText($user->phone, 'Escriba el mensaje que desea enviar. Arka01 lo entregará dentro del chat de la carrera.');

            return true;
        }

        if ($conversation->pending_intent === 'RIDE_CHAT') {
            return $this->sendChatMessage($user, $rawText, $conversation);
        }

        return false;
    }

    private function respondToOffer(User $driver, string $action, int $rideRequestId): bool
    {
        if (! WhatsAppRideAccess::driverCanOperate($driver)) {
            WhatsAppFreeformSender::sendText($driver->phone, 'La operación de carreras por WhatsApp está deshabilitada. Revise la solicitud desde Arka01.');

            return true;
        }

        $rideRequest = RideRequest::query()->with(['client', 'fleet'])->find($rideRequestId);
        if (! $rideRequest || $rideRequest->driver_user_id !== $driver->id || $rideRequest->status !== 'pending') {
            WhatsAppFreeformSender::sendText($driver->phone, 'Esa solicitud ya no está disponible o fue atendida por otra unidad.');

            return true;
        }

        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $driver);

        try {
            $controller = app(RideRequestController::class);
            if ($action === 'reject') {
                $controller->reject($request, $rideRequest);
                WhatsAppFreeformSender::sendText($driver->phone, 'Solicitud rechazada. Si pertenecía a una bolsa, Arka01 la ofrecerá a la siguiente unidad elegible.');

                return true;
            }

            $controller->accept($request, $rideRequest);
            $ride = Ride::query()->where('ride_request_id', $rideRequest->id)->with(['client', 'driver.driverProfile'])->firstOrFail();
            $message = $ride->status === 'scheduled'
                ? '✅ Carrera programada aceptada. La encontrará en sus próximos viajes.'
                : '✅ Carrera aceptada. La aplicación también fue actualizada y el cliente recibió el aviso.';
            WhatsAppFreeformSender::sendButtons($driver->phone, $message, [
                ['id' => 'ride_chat:'.$ride->id, 'title' => 'Mensaje al cliente'],
            ]);
            WhatsAppFreeformSender::sendLocation(
                $driver->phone,
                (float) $ride->origin_lat,
                (float) $ride->origin_lng,
                'Punto de recogida',
                $ride->origin_address,
            );
        } catch (Throwable $exception) {
            report($exception);
            WhatsAppFreeformSender::sendText($driver->phone, 'No fue posible realizar esa acción. La solicitud pudo cambiar de estado; revísela en Arka01.');
        }

        return true;
    }

    /**
     * "Cancelar carrera o solicitud" (pedido explícito del usuario): botón
     * simple, sin pedir motivo — reusa RideController::cancel() de la app
     * con un motivo fijo ("Otro motivo", ya existe en DRIVER_CANCEL_REASONS/
     * CLIENT_CANCEL_REASONS), igual que respondToOffer() reusa
     * RideRequestController::accept/reject. Sirve tanto para el cliente
     * como para el conductor: los dos pueden cancelar una carrera
     * 'scheduled'/'in_progress', mismo criterio que ya vale en la app.
     */
    private function cancelRide(User $user, int $rideId): bool
    {
        $ride = Ride::query()->with(['client', 'driver'])->find($rideId);
        if (! $ride || ! in_array($user->id, [$ride->client_user_id, $ride->driver_user_id], true)) {
            WhatsAppFreeformSender::sendText($user->phone, 'Esa carrera ya no está disponible.');

            return true;
        }

        if (! in_array($ride->status, ['scheduled', 'in_progress'], true)) {
            WhatsAppFreeformSender::sendText($user->phone, 'Esa carrera ya no se puede cancelar.');

            return true;
        }

        $isDriver = $ride->driver_user_id === $user->id;
        if ($isDriver && ! WhatsAppRideAccess::driverCanOperate($user)) {
            WhatsAppFreeformSender::sendText($user->phone, 'La operación de carreras por WhatsApp está deshabilitada. Cancele desde Arka01.');

            return true;
        }
        if (! $isDriver && ! WhatsAppRideAccess::clientCanBook()) {
            WhatsAppFreeformSender::sendText($user->phone, 'Esta opción está deshabilitada. Cancele desde Arka01.');

            return true;
        }

        $request = Request::create('/', 'POST', [
            'reason' => 'Otro motivo',
            'note' => 'Cancelado desde WhatsApp.',
        ]);
        $request->setUserResolver(fn () => $user);

        try {
            app(RideController::class)->cancel($request, $ride);
            WhatsAppFreeformSender::sendText($user->phone, '❌ Carrera cancelada.');
        } catch (Throwable $exception) {
            report($exception);
            WhatsAppFreeformSender::sendText($user->phone, 'No fue posible cancelar. La carrera pudo cambiar de estado; revísela en Arka01.');
        }

        return true;
    }

    private function sendChatMessage(User $sender, string $body, ChatbotConversation $conversation): bool
    {
        $ride = Ride::query()->with(['client', 'driver'])->find($conversation->context['ride_id'] ?? null);
        if (! $ride || ! $ride->chatIsOpen() || ! in_array($sender->id, [$ride->client_user_id, $ride->driver_user_id], true)) {
            $conversation->update(['pending_intent' => null, 'context' => null]);
            WhatsAppFreeformSender::sendText($sender->phone, 'El chat de esa carrera ya se cerró.');

            return true;
        }

        $message = RideMessage::query()->create([
            'ride_id' => $ride->id,
            'sender_user_id' => $sender->id,
            'body' => mb_substr(trim($body), 0, 500),
        ]);
        $message->setRelation('sender', $sender);
        broadcast(new RideMessageSent($message));

        $recipient = $sender->id === $ride->client_user_id ? $ride->driver : $ride->client;
        if ($recipient->phone && $recipient->hasActiveWhatsAppSession()) {
            WhatsAppFreeformSender::sendButtons($recipient->phone, "💬 {$sender->name}: {$message->body}", [
                ['id' => 'ride_chat:'.$ride->id, 'title' => 'Responder'],
            ]);
        }

        $conversation->update(['pending_intent' => null, 'context' => null, 'last_message_at' => now()]);
        WhatsAppFreeformSender::sendText($sender->phone, 'Mensaje enviado y sincronizado con el chat de Arka01.');

        return true;
    }
}
