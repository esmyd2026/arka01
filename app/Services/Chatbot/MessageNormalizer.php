<?php

namespace App\Services\Chatbot;

/**
 * Una sola regla de normalización de texto, compartida entre la detección
 * de intención (ChatbotIntentKeyword) y la búsqueda de preguntas frecuentes
 * (Faq) — minúsculas, sin tildes, sin puntuación, espacios colapsados. Sin
 * esto cada lado podría normalizar distinto y "no me llegó" no matchearía
 * contra "no me llego" guardado de otra forma.
 */
class MessageNormalizer
{
    private const ACCENTED = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'];

    private const PLAIN = ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'u', 'n'];

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(self::ACCENTED, self::PLAIN, $text);
        // Deja letras, números y espacios — el resto (signos de puntuación,
        // emojis) se convierte en espacio para no pegar dos palabras.
        $text = preg_replace('/[^a-z0-9 ]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
