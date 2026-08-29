<?php

namespace App\Services\Ride;

use App\Events\RideMessageSent;
use App\Models\Ride;
use App\Models\RideMessage;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Chat temporal cliente↔conductor — extraído de RideMessageController::store()
 * (roadmap app móvil, Hito 5: nunca duplicar una regla de negocio entre web
 * y móvil). Solo existe mientras la carrera está programada o en curso
 * entre esas dos personas puntuales (Ride::chatIsOpen()).
 */
class RideMessageSender
{
    public function send(Ride $ride, User $sender, string $body): RideMessage
    {
        $userId = $sender->id;

        if ($ride->client_user_id !== $userId && $ride->driver_user_id !== $userId) {
            abort(403);
        }

        if (! $ride->chatIsOpen()) {
            throw ValidationException::withMessages([
                'body' => 'El chat de esta carrera ya está cerrado.',
            ]);
        }

        $message = RideMessage::query()->create([
            'ride_id' => $ride->id,
            'sender_user_id' => $userId,
            'body' => $body,
        ]);

        $message->setRelation('sender', $sender);

        broadcast(new RideMessageSent($message))->toOthers();

        return $message;
    }
}
