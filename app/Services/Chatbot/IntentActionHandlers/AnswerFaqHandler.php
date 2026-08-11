<?php

namespace App\Services\Chatbot\IntentActionHandlers;

use App\Models\Faq;
use App\Services\Chatbot\MessageNormalizer;
use Illuminate\Support\Collection;

/**
 * Preguntas frecuentes (pedido explícito del usuario, sección 10) —
 * reutiliza App\Models\Faq tal cual, el mismo catálogo administrable desde
 * /admin/preguntas-frecuentes que ya usa el Centro de Ayuda dentro de la
 * app. No se crea ningún catálogo de FAQ paralelo para el chatbot.
 */
class AnswerFaqHandler
{
    /**
     * Umbral de similitud (0–100) para considerar que el mensaje libre
     * realmente pregunta por esa FAQ puntual — por debajo de esto, mejor no
     * arriesgar una respuesta que no venía al caso.
     */
    private const SIMILARITY_THRESHOLD = 45;

    private function activeFaqs(?string $role): Collection
    {
        $query = Faq::query()->where('is_active', true)->orderBy('sort_order');

        return $role ? $query->forAudience($role)->get() : $query->get();
    }

    /**
     * Intento de "rescate" (pedido explícito del usuario, sección 3):
     * cuando el mensaje libre no calzó con ninguna intención fija, antes de
     * rendirse se compara contra las preguntas reales del catálogo — cubre
     * frases como "¿cómo pido una carrera?" o "¿cómo funcionan las
     * tarifas?" que no tienen una intención dedicada, pero sí una FAQ.
     */
    public function findBestMatch(string $rawMessage, ?string $role): ?Faq
    {
        $normalized = MessageNormalizer::normalize($rawMessage);
        if ($normalized === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($this->activeFaqs($role) as $faq) {
            $target = MessageNormalizer::normalize($faq->question);
            similar_text($normalized, $target, $percent);

            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $faq;
            }
        }

        return $bestScore >= self::SIMILARITY_THRESHOLD ? $best : null;
    }

    /**
     * Cuando la intención PREGUNTA_FRECUENTE se elige de forma genérica
     * ("tengo una duda", sin decir cuál) — se ofrece una lista corta para
     * elegir, en vez de adivinar.
     */
    public function menuText(?string $role): string
    {
        $faqs = $this->activeFaqs($role)->take(6);

        if ($faqs->isEmpty()) {
            return 'Todavía no tengo preguntas frecuentes cargadas — contame directamente tu duda y te ayudo.';
        }

        $list = $faqs->map(fn (Faq $faq, int $i) => ($i + 1).'. '.$faq->question)->implode("\n");

        return "Estas son algunas preguntas frecuentes:\n\n{$list}\n\nEscribime el número, o preguntá directamente con tus palabras.";
    }
}
