<?php

namespace App\Http\Controllers;

use App\Events\RideMessageSent;
use App\Models\Ride;
use App\Models\RideMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Chat temporal cliente↔conductor (sección 10 del roadmap de mejoras): solo
 * existe mientras la carrera está programada o en curso entre esas dos
 * personas puntuales — nunca antes de que el conductor acepte, ni después de
 * que la carrera termine o se cancele (Ride::chatIsOpen()). No hay pantalla
 * de "mis chats": cada carrera es su propio hilo, ya visible en Ride/Show.vue.
 */
class RideMessageController extends Controller
{
    /**
     * Los mensajes de rutina (respuestas rápidas + texto libre) no necesitan
     * Inertia — un POST por axios devuelve el mensaje recién creado y el
     * propio remitente lo agrega a su lista sin esperar el eco por
     * WebSocket, que sí le llega a la otra parte (ver Ride/Show.vue).
     */
    public function store(Request $request, Ride $ride): JsonResponse
    {
        $userId = $request->user()->id;

        if ($ride->client_user_id !== $userId && $ride->driver_user_id !== $userId) {
            abort(403);
        }

        if (! $ride->chatIsOpen()) {
            throw ValidationException::withMessages([
                'body' => 'El chat de esta carrera ya está cerrado.',
            ]);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500'],
        ]);

        $message = RideMessage::query()->create([
            'ride_id' => $ride->id,
            'sender_user_id' => $userId,
            'body' => $validated['body'],
        ]);

        $message->setRelation('sender', $request->user());

        broadcast(new RideMessageSent($message))->toOthers();

        return response()->json([
            'id' => $message->id,
            'ride_id' => $message->ride_id,
            'sender_user_id' => $message->sender_user_id,
            'sender_name' => $request->user()->name,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
        ]);
    }
}
