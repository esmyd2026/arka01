<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotConversation;
use App\Models\ChatbotIntent;
use App\Models\ChatbotSetting;
use App\Models\ChatbotUnrecognizedMessage;
use App\Models\Faq;
use App\Models\User;
use App\Services\Chatbot\IntentActionHandlers\AnswerFaqHandler;
use App\Services\Chatbot\IntentActionHandlers\EscalateToSupportHandler;
use App\Services\Chatbot\IntentActionHandlers\ResendVerificationCodeHandler;
use App\Services\SystemEventLogger;
use App\Services\WhatsAppFreeformSender;
use Throwable;

/**
 * Orquestador del chatbot (pedido explícito del usuario, sección 9 —
 * pipeline completo): normalización → contexto → intención → confianza →
 * flujo/acción → respuesta, usando App\Services\Chatbot\IntentDetector para
 * las primeras cuatro etapas. Llamado desde App\Jobs\ProcessChatbotMessage,
 * nunca directo desde el webhook (esa parte sigue igual, ver
 * WhatsAppWebhookController::receive()).
 */
class ChatbotEngine
{
    public function __construct(
        private readonly IntentDetector $detector,
        private readonly ResendVerificationCodeHandler $resendHandler,
        private readonly EscalateToSupportHandler $escalateHandler,
        private readonly AnswerFaqHandler $faqHandler,
        private readonly WhatsAppRideActionHandler $rideActionHandler,
        private readonly WhatsAppRideBookingHandler $rideBookingHandler,
    ) {}

    public function respondTo(string $phoneE164, ?User $user, string $rawText, array $metadata = []): void
    {
        try {
            $this->process($phoneE164, $user, $rawText, $metadata);
        } catch (Throwable $e) {
            // Pedido explícito del usuario (sección 14): nunca mostrar
            // detalle técnico — y nunca dejar que un error del motor
            // reviente el job ni deje al usuario sin ninguna respuesta.
            SystemEventLogger::log(
                eventType: 'chatbot_engine_failed',
                module: 'chatbot',
                message: 'El motor del chatbot falló al procesar un mensaje.',
                context: ['phone' => $phoneE164, 'error' => $e->getMessage()],
                userId: $user?->id,
                channel: 'whatsapp',
            );

            WhatsAppFreeformSender::sendText(
                $phoneE164,
                'Algo falló de nuestro lado procesando tu mensaje. Podés intentarlo de nuevo, o escribir "soporte" para hablar con alguien.'
            );
        }
    }

    private function process(string $phoneE164, ?User $user, string $rawText, array $metadata): void
    {
        $conversation = ChatbotConversation::forPhone($phoneE164);
        if ($user && ! $conversation->user_id) {
            $conversation->update(['user_id' => $user->id]);
        }

        if ($user && $this->rideActionHandler->handle($user, $rawText, $conversation)) {
            return;
        }

        if ($this->rideBookingHandler->handle($phoneE164, $user, $rawText, $metadata, $conversation)) {
            return;
        }

        // Solo cliente/conductor participan del catálogo con rol — un admin
        // (o un número sin cuenta) ve el contenido "ambos" nada más.
        $role = in_array($user?->role, ['cliente', 'conductor'], true) ? $user->role : null;

        // Elegir una FAQ de la mini-lista que se le ofreció recién (ver
        // AnswerFaqHandler::menuText()) — resolución previa a la detección
        // normal, mismo principio de "el contexto manda" pero con una forma
        // de estado distinta a un menú de intenciones.
        if ($conversation->pending_intent === 'AWAITING_FAQ_CHOICE') {
            $faqReply = $this->resolveFaqChoice($rawText, $conversation);
            if ($faqReply !== null) {
                $this->resolve($conversation, $faqReply, pendingIntent: null, context: null);

                return;
            }
        }

        $match = $this->detector->detect($rawText, $conversation, $role);

        if (! $match->isUnknown()) {
            // "Pedir una carrera" desde el menú (pedido explícito del
            // usuario: "que por allí se pueda pedir también una carrera pero
            // con botones") — el flujo con botones ya existía
            // (WhatsAppRideBookingHandler), solo no se podía DESCUBRIR desde
            // el menú, había que ya saber escribir "pedir carrera" a mano.
            // Reusa el mismo `handle()` que dispara esa frase escrita
            // directo — nada de lógica duplicada, y ya sabe manejar tanto
            // cuentas existentes como números nuevos.
            if ($match->intent->action === 'start_ride_booking') {
                $this->rideBookingHandler->handle($phoneE164, $user, 'pedir carrera', $metadata, $conversation);

                return;
            }

            [$reply, $pendingIntent, $context] = $this->buildReply($match->intent, $user, $conversation, $rawText, $role, $phoneE164);
            $this->resolve($conversation, $reply, $pendingIntent, $context);

            return;
        }

        // Intento de rescate contra el catálogo de FAQ antes de darse por
        // vencido (sección 3 y 10) — muchas preguntas reales no tienen una
        // intención fija dedicada, pero sí una FAQ que las responde.
        $faqMatch = $this->faqHandler->findBestMatch($rawText, $role);
        if ($faqMatch) {
            $this->resolve($conversation, "{$faqMatch->question}\n\n{$faqMatch->answer}", null, null);

            return;
        }

        $this->fallback($phoneE164, $user, $role, $rawText, $conversation, $match->confidence);
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?array}
     */
    private function buildReply(ChatbotIntent $intent, ?User $user, ChatbotConversation $conversation, string $rawText, ?string $role, string $phoneE164): array
    {
        return match ($intent->action) {
            'show_menu' => $this->menuReply($role),
            'resend_code' => [$this->resendHandler->handle($user), null, null],
            'escalate_support' => [$this->escalateHandler->handle($user, $rawText, $phoneE164), null, null],
            'answer_faq' => $this->faqMenuReply($role),
            default => [$intent->reply_message ?? 'Listo.', null, null],
        };
    }

    /**
     * @return array{0: string, 1: string, 2: array}
     */
    private function menuReply(?string $role): array
    {
        $settings = ChatbotSetting::current();
        [$menuText, $codes] = $this->buildMenu($role);

        return [trim($settings->welcome_message."\n\n".$menuText), 'AWAITING_MENU_CHOICE', ['menu_options' => $codes]];
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?array}
     */
    private function faqMenuReply(?string $role): array
    {
        $faqs = Faq::query()->where('is_active', true)->orderBy('sort_order')
            ->when($role, fn ($q) => $q->forAudience($role))
            ->take(6)
            ->get();

        if ($faqs->isEmpty()) {
            return [$this->faqHandler->menuText($role), null, null];
        }

        $list = $faqs->values()->map(fn (Faq $faq, int $i) => ($i + 1).'. '.$faq->question)->implode("\n");
        $text = "Estas son algunas preguntas frecuentes:\n\n{$list}\n\nEscribime el número, o preguntá directamente con tus palabras.";

        return [$text, 'AWAITING_FAQ_CHOICE', ['faq_ids' => $faqs->pluck('id')->all()]];
    }

    private function resolveFaqChoice(string $rawText, ChatbotConversation $conversation): ?string
    {
        $faqIds = $conversation->context['faq_ids'] ?? [];
        $normalized = MessageNormalizer::normalize($rawText);

        if (! ctype_digit($normalized) || ! isset($faqIds[((int) $normalized) - 1])) {
            return null;
        }

        $faq = Faq::query()->find($faqIds[((int) $normalized) - 1]);

        return $faq ? "{$faq->question}\n\n{$faq->answer}" : null;
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function buildMenu(?string $role): array
    {
        $intents = ChatbotIntent::query()
            ->where('is_active', true)
            ->where('show_in_menu', true)
            ->forRole($role)
            ->orderBy('sort_order')
            ->get();

        $lines = $intents->values()->map(fn (ChatbotIntent $intent, int $i) => ($i + 1).'. '.($intent->menu_label ?? $intent->label))->implode("\n");

        return [$lines, $intents->pluck('code')->all()];
    }

    private function resolve(ChatbotConversation $conversation, string $reply, ?string $pendingIntent, ?array $context): void
    {
        $conversation->update([
            'unresolved_attempts' => 0,
            'pending_intent' => $pendingIntent,
            'context' => $context,
            'last_message_at' => now(),
        ]);

        WhatsAppFreeformSender::sendText($conversation->phone, $reply);
    }

    /**
     * Fallback profesional (pedido explícito del usuario, sección 12): nunca
     * se queda callado. Pasado el máximo de intentos configurado, ofrece
     * hablar con soporte en vez de repetir el mismo mensaje una y otra vez.
     */
    private function fallback(string $phoneE164, ?User $user, ?string $role, string $rawText, ChatbotConversation $conversation, int $confidence): void
    {
        ChatbotUnrecognizedMessage::query()->create([
            'phone' => $phoneE164,
            'user_id' => $user?->id,
            'role' => $role,
            'message' => $rawText,
            'best_guess_intent_code' => null,
            'confidence' => $confidence,
        ]);

        $settings = ChatbotSetting::current();
        $attempts = $conversation->unresolved_attempts + 1;

        if ($attempts >= $settings->max_fallback_attempts) {
            $conversation->update([
                'unresolved_attempts' => $attempts,
                'pending_intent' => 'AWAITING_MENU_CHOICE',
                'context' => ['menu_options' => ['SOPORTE']],
                'last_message_at' => now(),
            ]);

            WhatsAppFreeformSender::sendText(
                $phoneE164,
                trim($settings->fallback_escalation_message."\n\n1. 💬 Hablar con soporte")
            );

            return;
        }

        [$menuText] = $this->buildMenu($role);

        $conversation->update([
            'unresolved_attempts' => $attempts,
            'pending_intent' => null,
            'context' => null,
            'last_message_at' => now(),
        ]);

        WhatsAppFreeformSender::sendText($phoneE164, trim($settings->fallback_message."\n\n".$menuText));
    }
}
