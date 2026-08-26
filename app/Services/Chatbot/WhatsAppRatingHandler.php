<?php

namespace App\Services\Chatbot;

use App\Http\Controllers\ReviewController;
use App\Models\ChatbotConversation;
use App\Models\RatingReason;
use App\Models\Ride;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Calificar sin salir de WhatsApp (pedido explícito del usuario: "que
 * califique por allí también y le invite a seguir las redes") — la lista de
 * estrellas la manda WhatsAppFreeformSender::sendRideCompletedToClient();
 * acá se procesa la elección. Reusa ReviewController::store() (el mismo
 * controlador que la web) en vez de duplicar sus reglas — una carrera no
 * completada, ya calificada, o de otro cliente, se rechaza exactamente
 * igual que si lo intentara desde la app.
 */
class WhatsAppRatingHandler
{
    public function handle(string $phone, ?User $user, string $text, ChatbotConversation $conversation): bool
    {
        if (! $user) {
            return false;
        }

        if (preg_match('/^wa_rate:(\d+):([1-5])$/', $text, $match)) {
            $ride = Ride::find((int) $match[1]);
            if (! $ride || $ride->client_user_id !== $user->id) {
                return false;
            }

            $stars = (int) $match[2];
            if ($stars === 5) {
                return $this->submitRating($phone, $user, $conversation, $ride, 5, null);
            }

            // Pedido explícito del usuario: cuando baja de 5 estrellas, un
            // motivo es obligatorio (ver ReviewController::store()) — se
            // pide con una segunda lista, guardando ride/estrellas en el
            // contexto hasta que elija una.
            $conversation->update([
                'pending_intent' => 'WA_RATING_REASON',
                'context' => ['rating_ride_id' => $ride->id, 'rating_stars' => $stars],
                'last_message_at' => now(),
            ]);

            $reasons = RatingReason::query()
                ->where('direction', 'client_to_driver')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            if ($reasons->isEmpty()) {
                // Sin catálogo cargado no hay motivo que ofrecer — se
                // guarda igual sin uno (ReviewController solo lo exige si
                // existe alguno válido para esa dirección... en realidad
                // lo exige siempre para <5, así que sin catálogo esto
                // fallaría en store() con un mensaje claro; se avisa acá
                // directo, más rápido para el cliente).
                $conversation->update(['pending_intent' => null, 'context' => null]);
                WhatsAppFreeformSender::sendText($phone, 'Gracias por avisar — no pudimos guardar el motivo ahora, pero puede calificar desde Arka01 con más detalle.');

                return true;
            }

            WhatsAppFreeformSender::sendList(
                $phone,
                '¿Qué pasó? Elija el motivo más cercano:',
                'Elegir',
                $reasons->map(fn (RatingReason $reason) => ['id' => "wa_reason:{$reason->id}", 'title' => $reason->text])->all()
            );

            return true;
        }

        if ($conversation->pending_intent === 'WA_RATING_REASON' && preg_match('/^wa_reason:(\d+)$/', $text, $match)) {
            $context = $conversation->context ?? [];
            $ride = Ride::find($context['rating_ride_id'] ?? null);

            if (! $ride) {
                $conversation->update(['pending_intent' => null, 'context' => null]);
                WhatsAppFreeformSender::sendText($phone, 'Algo falló, escriba "pedir carrera" para volver a empezar.');

                return true;
            }

            return $this->submitRating($phone, $user, $conversation, $ride, (int) $context['rating_stars'], (int) $match[1]);
        }

        return false;
    }

    private function submitRating(string $phone, User $user, ChatbotConversation $conversation, Ride $ride, int $stars, ?int $reasonId): bool
    {
        $request = Request::create("/carreras/{$ride->id}/calificar", 'POST', [
            'rating' => $stars,
            'rating_reason_id' => $reasonId,
        ]);
        $request->setUserResolver(fn () => $user);

        try {
            app(ReviewController::class)->store($request, $ride);
        } catch (ValidationException $e) {
            WhatsAppFreeformSender::sendText($phone, 'No pudimos guardar la calificación: '.collect($e->errors())->flatten()->first());
            $conversation->update(['pending_intent' => null, 'context' => null]);

            return true;
        }

        $conversation->update(['pending_intent' => null, 'context' => null]);

        if ($stars < 5) {
            WhatsAppFreeformSender::sendText($phone, 'Gracias por la calificación — vamos a revisar lo que pasó.');

            return true;
        }

        // Pedido explícito del usuario ("le invite a seguir las redes") —
        // solo con una calificación buena, nunca después de una mala.
        $site = SiteSetting::current();
        $socialLines = collect([
            $site->facebook_url ? "Facebook: {$site->facebook_url}" : null,
            $site->instagram_url ? "Instagram: {$site->instagram_url}" : null,
            $site->tiktok_url ? "TikTok: {$site->tiktok_url}" : null,
        ])->filter()->implode("\n");

        $message = '⭐ ¡Gracias por calificarnos con 5 estrellas!';
        if ($socialLines !== '') {
            $message .= "\n\nSíganos para enterarse de promociones y novedades:\n{$socialLines}";
        }

        WhatsAppFreeformSender::sendText($phone, $message);

        return true;
    }
}
