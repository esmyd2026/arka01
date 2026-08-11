<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotConversation;
use App\Models\ChatbotIntent;

/**
 * Detección de intención por reglas + palabras clave (decisión confirmada
 * con el usuario: sin depender de un modelo de lenguaje externo — gratis,
 * instantáneo, 100% administrable agregando vocablos desde /admin/chatbot).
 *
 * Pipeline (pedido explícito del usuario, sección 9): mensaje →
 * normalización (MessageNormalizer) → contexto → detección de intención →
 * nivel de confianza → [ChatbotEngine sigue con el flujo/acción].
 */
class IntentDetector
{
    /**
     * Por debajo de esto, se clasifica DESCONOCIDO aunque algo haya
     * matcheado — evita "adivinar" con muy poca base.
     */
    private const MIN_CONFIDENCE = 40;

    public function detect(string $rawMessage, ?ChatbotConversation $conversation, ?string $role): IntentMatch
    {
        $normalized = MessageNormalizer::normalize($rawMessage);

        // El contexto tiene prioridad (pedido explícito del usuario,
        // sección 8): si el bot acaba de mostrar un menú y la respuesta
        // encaja con una de esas opciones, no hace falta correr el
        // reconocimiento genérico — se resuelve directo con confianza total.
        $contextMatch = $this->matchFromContext($normalized, $conversation);
        if ($contextMatch) {
            return $contextMatch;
        }

        return $this->matchByKeywords($normalized, $role);
    }

    private function matchFromContext(string $normalized, ?ChatbotConversation $conversation): ?IntentMatch
    {
        if (! $conversation || $conversation->pending_intent !== 'AWAITING_MENU_CHOICE') {
            return null;
        }

        $menuOptions = $conversation->context['menu_options'] ?? [];
        if (! $menuOptions) {
            return null;
        }

        // Eligió por número ("1", "2"...) — el orden en que se mostró el menú.
        if (ctype_digit($normalized) && isset($menuOptions[((int) $normalized) - 1])) {
            $code = $menuOptions[((int) $normalized) - 1];
        } else {
            // O escribió (parte de) la etiqueta del botón tal cual.
            $code = collect($menuOptions)->first(function ($intentCode) use ($normalized) {
                $intent = ChatbotIntent::query()->where('code', $intentCode)->first();
                $label = MessageNormalizer::normalize($intent?->menu_label ?? $intent?->label ?? '');

                return $label && (str_contains($normalized, $label) || str_contains($label, $normalized));
            });
        }

        if (! $code) {
            return null;
        }

        $intent = ChatbotIntent::query()->where('code', $code)->where('is_active', true)->first();

        return $intent ? new IntentMatch($intent, 100, fromContext: true) : null;
    }

    private function matchByKeywords(string $normalized, ?string $role): IntentMatch
    {
        if ($normalized === '') {
            return new IntentMatch(null, 0);
        }

        $intents = ChatbotIntent::query()
            ->where('is_active', true)
            ->forRole($role)
            ->with('keywords')
            ->get();

        $scores = [];
        foreach ($intents as $intent) {
            $score = 0;
            foreach ($intent->keywords as $keyword) {
                if ($keyword->phrase !== '' && str_contains($normalized, $keyword->phrase)) {
                    $score += $keyword->weight;
                }
            }
            if ($score > 0) {
                $scores[$intent->id] = $score;
            }
        }

        if (! $scores) {
            return new IntentMatch(null, 0);
        }

        arsort($scores);
        $bestIntentId = array_key_first($scores);
        $bestScore = $scores[$bestIntentId];
        $secondBestScore = array_values($scores)[1] ?? 0;

        // Fórmula deliberadamente simple (documentada acá para poder
        // ajustarla sin arqueología): una sola palabra clave de peso 1 da
        // 20% de confianza; un peso acumulado de 5+ satura en 100%. Si el
        // segundo puesto queda muy cerca del primero, se penaliza a la
        // mitad — el mensaje era ambiguo entre dos intenciones, no hay que
        // sonar tan seguro.
        $confidence = min(100, $bestScore * 20);
        if ($secondBestScore > 0 && $secondBestScore >= $bestScore * 0.8) {
            $confidence = (int) round($confidence / 2);
        }

        if ($confidence < self::MIN_CONFIDENCE) {
            return new IntentMatch(null, $confidence);
        }

        $bestIntent = $intents->firstWhere('id', $bestIntentId);

        return new IntentMatch($bestIntent, $confidence);
    }
}
