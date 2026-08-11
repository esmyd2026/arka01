<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotIntent;

/**
 * Resultado de App\Services\Chatbot\IntentDetector::detect() — el pipeline
 * completo del pedido del usuario: mensaje → normalización → contexto →
 * detección de intención → nivel de confianza → (acá termina esta clase,
 * sigue en ChatbotEngine) → flujo/acción.
 */
final readonly class IntentMatch
{
    public function __construct(
        public ?ChatbotIntent $intent,
        public int $confidence,
        public bool $fromContext = false,
    ) {}

    public function code(): string
    {
        return $this->intent?->code ?? 'DESCONOCIDO';
    }

    public function isUnknown(): bool
    {
        return $this->intent === null;
    }
}
