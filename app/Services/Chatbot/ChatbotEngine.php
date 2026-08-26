<?php

namespace App\Services\Chatbot;

use App\Events\SupportMessageSent;
use App\Models\ChatbotConversation;
use App\Models\ChatbotIntent;
use App\Models\ChatbotSetting;
use App\Models\ChatbotUnrecognizedMessage;
use App\Models\Faq;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Chatbot\IntentActionHandlers\AnswerFaqHandler;
use App\Services\Chatbot\IntentActionHandlers\EscalateToSupportHandler;
use App\Services\Chatbot\IntentActionHandlers\ResendVerificationCodeHandler;
use App\Services\SystemEventLogger;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Support\Str;
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
    /**
     * Pseudo-acción del botón "Más opciones" (pedido explícito del usuario:
     * "chatbot mas pro... los primeros 3 con lo mas importante... y el
     * otro más. y alli se abre el de seleccion") — NO es un ChatbotIntent
     * real en la base de datos, solo un `id` de botón que este motor
     * reconoce directo, antes de pasar por IntentDetector (mismo criterio
     * que rideActionHandler/rideBookingHandler abajo).
     */
    private const MORE_OPTIONS_ACTION = 'WA_MAS_OPCIONES';

    /**
     * Las 2 intenciones promovidas a botón propio en el menú principal.
     * Pedido explícito del usuario, después de probar el bot de verdad:
     * "primero el soy cliente debe ir al inicio... y deberia estar en mas
     * informacion solicitar carrera" — INFORMACION_CLIENTE ("Soy cliente")
     * reemplaza a PEDIR_CARRERA acá; PEDIR_CARRERA vuelve a la lista de
     * "Más Info" (mismo criterio que REGISTRO desde que se sacó de acá),
     * pero sigue ofreciéndose como uno de los 2 botones que manda
     * sendClientMenu() al tocar "Soy cliente" — no hace falta ir a "Más
     * Info" para pedir una carrera, solo ya no es EL botón principal.
     */
    private const PROMOTED_MENU_CODES = ['INFORMACION_CLIENTE', 'SOY_CONDUCTOR'];

    public function __construct(
        private readonly IntentDetector $detector,
        private readonly ResendVerificationCodeHandler $resendHandler,
        private readonly EscalateToSupportHandler $escalateHandler,
        private readonly AnswerFaqHandler $faqHandler,
        private readonly WhatsAppRideActionHandler $rideActionHandler,
        private readonly WhatsAppRideBookingHandler $rideBookingHandler,
        private readonly WhatsAppDriverConnectHandler $driverConnectHandler,
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
                'Algo falló de nuestro lado procesando tu mensaje. Puedes intentarlo de nuevo, o escribir "soporte" para hablar con alguien.'
            );
        }
    }

    private function process(string $phoneE164, ?User $user, string $rawText, array $metadata): void
    {
        $conversation = ChatbotConversation::forPhone($phoneE164);
        if ($user && ! $conversation->user_id) {
            $conversation->update(['user_id' => $user->id]);
        }

        // Pedido explícito del usuario ("poder responder desde allí yo
        // también o activar el bot o no") — control manual por conversación
        // desde el inbox de WhatsApp del admin (Admin\WhatsAppInboxController::
        // toggleBot()), independiente del pausado automático por ticket de
        // soporte de abajo. El mensaje ya quedó logueado en ChatbotMessage
        // (ver WhatsAppWebhookController::receive()) — acá solo se corta el
        // pipeline, no hace falta hacer nada más.
        if ($conversation->bot_paused) {
            return;
        }

        // Pedido explícito del usuario ("ayudame a ver la trazabilidad...
        // y tomar control humana"): si ya hay un admin atendiendo el
        // ticket de este usuario, el bot se calla del todo — el mensaje se
        // suma al hilo del ticket (mismo canal en vivo que ya usa
        // Admin/Support/Show.vue) en vez de contestar con el menú de
        // siempre por encima de la conversación humana.
        if ($user && $this->humanIsHandling($user, $rawText)) {
            return;
        }

        if ($user && $this->rideActionHandler->handle($user, $rawText, $conversation)) {
            return;
        }

        if ($this->driverConnectHandler->handle($phoneE164, $user, $rawText, $conversation)) {
            return;
        }

        if ($this->rideBookingHandler->handle($phoneE164, $user, $rawText, $metadata, $conversation)) {
            return;
        }

        // Solo cliente/conductor participan del catálogo con rol — un admin
        // (o un número sin cuenta) ve el contenido "ambos" nada más.
        $role = in_array($user?->role, ['cliente', 'conductor'], true) ? $user->role : null;

        // Botón "Más opciones" del menú principal (pedido explícito del
        // usuario) — pseudo-acción, se resuelve directo sin pasar por
        // IntentDetector (no es un ChatbotIntent real, ver la constante).
        if ($rawText === self::MORE_OPTIONS_ACTION) {
            $this->sendMoreOptionsList($conversation, $phoneE164, $role);

            return;
        }

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

            // Mismo criterio que start_ride_booking de arriba: cubre el caso
            // de un match por texto/etiqueta que no cayó en el guard propio
            // del handler (ej. alguien escribe "Soy Conductor" tal cual la
            // etiqueta del botón, en vez de "conectarme").
            if ($match->intent->action === 'driver_menu') {
                $this->driverConnectHandler->handle($phoneE164, $user, 'soy conductor', $conversation);

                return;
            }

            // "Soy cliente" (pedido explícito del usuario, tras probar el
            // bot: "el soy cliente no funciona esta caido") — antes era
            // texto fijo terminando en una pregunta sin ningún botón real,
            // así que cualquier respuesta después caía al fallback
            // genérico. Ahora manda los mismos 2 botones reales que ya
            // tienen flujo propio (Pedir carrera / Crear cuenta), en vez de
            // dejar la conversación en un callejón sin salida.
            if ($match->intent->action === 'client_menu') {
                $this->sendClientMenu($conversation, $phoneE164, $match->intent);

                return;
            }

            // Preguntas frecuentes (pedido explícito del usuario: "que
            // seleccione por botones... no le pidas que ingrese la opción
            // en número") — antes era texto con una lista numerada
            // ("1. ¿...?"), ahora una lista real de WhatsApp. Escribir el
            // número sigue funcionando como respaldo (ver resolveFaqChoice()).
            if ($match->intent->action === 'answer_faq') {
                $this->sendFaqList($conversation, $phoneE164, $role);

                return;
            }

            // El menú principal (pedido explícito del usuario: "chatbot mas
            // pro... con botones") ya no es una respuesta de TEXTO como el
            // resto de buildReply() — es un mensaje interactivo aparte.
            if ($match->intent->action === 'show_menu') {
                $conversation->update(['unresolved_attempts' => 0]);
                $this->sendMainMenu($conversation, $phoneE164, ChatbotSetting::current()->welcome_message);

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
            // 'show_menu' ya no pasa por acá — ver el chequeo en process(),
            // el menú principal ahora es un mensaje interactivo de botones
            // (sendMainMenu()), no una respuesta de texto. 'answer_faq'
            // tampoco — ver sendFaqList().
            'resend_code' => [$this->resendHandler->handle($user), null, null],
            'escalate_support' => [$this->escalateHandler->handle($user, $rawText, $phoneE164), null, null],
            default => [$intent->reply_message ?? 'Listo.', null, null],
        };
    }

    /**
     * Preguntas frecuentes como lista real de WhatsApp (pedido explícito
     * del usuario: "que seleccione por botones... no le pidas que ingrese
     * la opción en número") — antes era texto con una lista numerada.
     */
    private function sendFaqList(ChatbotConversation $conversation, string $phone, ?string $role): void
    {
        $faqs = Faq::query()->where('is_active', true)->orderBy('sort_order')
            ->when($role, fn ($q) => $q->forAudience($role))
            ->take(10)
            ->get();

        if ($faqs->isEmpty()) {
            $this->resolve($conversation, $this->faqHandler->menuText($role), null, null);

            return;
        }

        WhatsAppFreeformSender::sendList(
            $phone,
            'Estas son algunas preguntas frecuentes:',
            'Ver preguntas',
            $faqs->map(fn (Faq $faq) => ['id' => 'FAQ:'.$faq->id, 'title' => Str::limit($faq->question, 24, '')])->all()
        );

        $conversation->update([
            'unresolved_attempts' => 0,
            'pending_intent' => 'AWAITING_FAQ_CHOICE',
            'context' => ['faq_ids' => $faqs->pluck('id')->all()],
            'last_message_at' => now(),
        ]);
    }

    private function resolveFaqChoice(string $rawText, ChatbotConversation $conversation): ?string
    {
        $faqIds = $conversation->context['faq_ids'] ?? [];

        // Tocar una fila de la lista manda el id exacto ("FAQ:12").
        if (preg_match('/^FAQ:(\d+)$/', $rawText, $match) && in_array((int) $match[1], $faqIds, true)) {
            $faq = Faq::query()->find((int) $match[1]);

            return $faq ? "{$faq->question}\n\n{$faq->answer}" : null;
        }

        // Respaldo: si igual escribe el número a mano.
        $normalized = MessageNormalizer::normalize($rawText);
        if (! ctype_digit($normalized) || ! isset($faqIds[((int) $normalized) - 1])) {
            return null;
        }

        $faq = Faq::query()->find($faqIds[((int) $normalized) - 1]);

        return $faq ? "{$faq->question}\n\n{$faq->answer}" : null;
    }

    /**
     * Menú principal con botones nativos de WhatsApp (pedido explícito del
     * usuario: "chatbot mas pro... los primeros 3 con lo mas importante
     * que es pedir una carrera y el otro crear cuenta y el otro más...
     * evitar que confirmen con numeros o escriban"). Máximo 3 botones por
     * mensaje (límite de la API de Meta) — "Soy cliente"/"Soy Conductor" van
     * siempre que existan y estén activos, sin filtrar por rol (ver el
     * comentario de más abajo); "Más opciones" siempre va, para llegar al
     * resto sin escribir nada.
     */
    private function sendMainMenu(ChatbotConversation $conversation, string $phone, string $introText): void
    {
        // Pedido explícito del usuario, insistiendo tras probarlo con un
        // número ya registrado como conductor: "Soy cliente" y "Soy
        // Conductor" son navegación, no una acción — tienen que verse los
        // DOS siempre en el menú de entrada, sin importar el rol real de la
        // cuenta (por eso `forRole()` NO se aplica acá, a propósito, aunque
        // sí se sigue usando en "Más opciones" y las FAQ). Si la cuenta no
        // puede de verdad hacer lo que el botón promete, eso se explica
        // recién al tocarlo (ver el mensaje de WhatsAppRideBookingHandler
        // cuando bloquea a alguien que no es cliente).
        $promoted = ChatbotIntent::query()
            ->whereIn('code', self::PROMOTED_MENU_CODES)
            ->where('is_active', true)
            ->get()
            ->keyBy('code');

        $buttons = collect(self::PROMOTED_MENU_CODES)
            ->filter(fn (string $code) => $promoted->has($code))
            ->map(fn (string $code) => ['id' => $code, 'title' => $promoted[$code]->label])
            ->values()
            ->push(['id' => self::MORE_OPTIONS_ACTION, 'title' => 'Más Info'])
            ->all();

        WhatsAppFreeformSender::sendButtons($phone, trim($introText), $buttons);

        // `unresolved_attempts` queda afuera a propósito: quien llama a
        // este método decide si corresponde resetearlo (un saludo/menú
        // pedido de verdad) o conservarlo (fallback() ya viene contando
        // intentos fallidos, ver ahí abajo).
        $conversation->update([
            'pending_intent' => 'AWAITING_MENU_CHOICE',
            'context' => ['menu_options' => array_column($buttons, 'id')],
            'last_message_at' => now(),
        ]);
    }

    /**
     * "Soy cliente": el texto informativo de siempre (`reply_message`) más 2
     * botones reales — Pedir carrera (mismo flujo de WhatsAppRideBookingHandler
     * de siempre, con o sin cuenta) y Crear cuenta (REGISTRO). Antes esto
     * terminaba en una pregunta sin ningún botón, un callejón sin salida
     * (pedido explícito del usuario, tras probar el bot: "esta caido").
     */
    private function sendClientMenu(ChatbotConversation $conversation, string $phone, ChatbotIntent $intent): void
    {
        $buttons = [
            ['id' => 'PEDIR_CARRERA', 'title' => 'Pedir carrera'],
            ['id' => 'REGISTRO', 'title' => 'Crear cuenta'],
        ];

        WhatsAppFreeformSender::sendButtons($phone, trim($intent->reply_message ?? '¿Qué desea hacer?'), $buttons);

        $conversation->update([
            'unresolved_attempts' => 0,
            'pending_intent' => 'AWAITING_MENU_CHOICE',
            'context' => ['menu_options' => array_column($buttons, 'id')],
            'last_message_at' => now(),
        ]);
    }

    /**
     * Lista de WhatsApp con el resto de las intenciones del menú (todo lo
     * que no se promovió a botón propio) — se abre al tocar "Más opciones".
     */
    private function sendMoreOptionsList(ChatbotConversation $conversation, string $phone, ?string $role): void
    {
        $intents = ChatbotIntent::query()
            ->where('is_active', true)
            ->where('show_in_menu', true)
            ->whereNotIn('code', self::PROMOTED_MENU_CODES)
            ->forRole($role)
            ->orderBy('sort_order')
            ->get();

        WhatsAppFreeformSender::sendList(
            $phone,
            'Elegí una opción de la lista:',
            'Ver opciones',
            $intents->map(fn (ChatbotIntent $intent) => ['id' => $intent->code, 'title' => $intent->menu_label ?? $intent->label])->all()
        );

        $conversation->update([
            'unresolved_attempts' => 0,
            'pending_intent' => 'AWAITING_MENU_CHOICE',
            'context' => ['menu_options' => $intents->pluck('code')->all()],
            'last_message_at' => now(),
        ]);
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

            WhatsAppFreeformSender::sendButtons($phoneE164, trim($settings->fallback_escalation_message), [
                ['id' => 'SOPORTE', 'title' => 'Hablar con soporte'],
            ]);

            return;
        }

        // Mismo menú de botones que el saludo (pedido explícito del
        // usuario: "chatbot mas pro") — sendMainMenu() ya deja el estado
        // AWAITING_MENU_CHOICE armado, no hace falta duplicarlo acá.
        $conversation->update(['unresolved_attempts' => $attempts]);
        $this->sendMainMenu($conversation, $phoneE164, $settings->fallback_message);
    }

    /**
     * true si hay un admin atendiendo de verdad este ticket ahora mismo
     * (`en_atencion`) o esperando que el usuario responda algo puntual
     * (`esperando_usuario` — `Admin\SupportTicketController::reply()` ya
     * transiciona a este estado solo con contestar, sin nada nuevo que
     * tocar acá). En ese caso el mensaje entrante NO es para el bot: se
     * suma al hilo del ticket como si el usuario le escribiera directo al
     * admin, y se transmite por el mismo canal que ya usa
     * Admin/Support/Show.vue para verlo en vivo.
     */
    private function humanIsHandling(User $user, string $rawText): bool
    {
        $ticket = SupportTicket::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['en_atencion', 'esperando_usuario'])
            ->latest()
            ->first();

        if (! $ticket) {
            return false;
        }

        $message = $ticket->messages()->create([
            'sender_user_id' => $user->id,
            'body' => $rawText,
        ]);

        broadcast(new SupportMessageSent($message))->toOthers();

        return true;
    }
}
