<?php

namespace Tests\Feature\Chatbot;

use App\Models\ChatbotConversation;
use App\Models\ChatbotIntent;
use App\Services\Chatbot\IntentDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Motor de detección de intención del chatbot (pedido explícito del
 * usuario): reglas + palabras clave con nivel de confianza, sin depender de
 * un modelo de lenguaje externo — ver App\Services\Chatbot\IntentDetector.
 * El catálogo de intenciones/vocablos ya viene sembrado por las migraciones
 * (mismo criterio que `faqs`), así que estos tests corren contra datos
 * reales, no fixtures armados a mano.
 */
class IntentDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function detector(): IntentDetector
    {
        return new IntentDetector;
    }

    public function test_a_plain_greeting_is_classified_as_saludo(): void
    {
        $match = $this->detector()->detect('Hola', null, null);

        $this->assertSame('SALUDO', $match->code());
        $this->assertGreaterThanOrEqual(40, $match->confidence);
    }

    public function test_a_greeting_ignores_accents_and_case(): void
    {
        $match = $this->detector()->detect('BUENOS DÍAS!!', null, null);

        $this->assertSame('SALUDO', $match->code());
    }

    public function test_a_specific_phrase_is_classified_correctly(): void
    {
        $match = $this->detector()->detect('no me llegó el código', null, null);

        $this->assertSame('CODIGO_NO_RECIBIDO', $match->code());
        $this->assertGreaterThanOrEqual(40, $match->confidence);
    }

    public function test_a_greeting_plus_a_real_problem_prioritizes_the_real_problem(): void
    {
        // Pedido explícito del usuario (sección 6): "hola no me llegó el
        // código" no debe quedarse solo en el saludo — CODIGO_NO_RECIBIDO
        // tiene una frase de peso 3 exacta contra ella, mucho más fuerte que
        // el "hola" suelto de SALUDO (peso 2).
        $match = $this->detector()->detect('hola no me llego el codigo', null, null);

        $this->assertSame('CODIGO_NO_RECIBIDO', $match->code());
    }

    public function test_gibberish_is_classified_as_unknown(): void
    {
        $match = $this->detector()->detect('asdfg qwerty zzz', null, null);

        $this->assertTrue($match->isUnknown());
        $this->assertSame('DESCONOCIDO', $match->code());
    }

    public function test_an_empty_message_is_unknown_with_zero_confidence(): void
    {
        $match = $this->detector()->detect('', null, null);

        $this->assertTrue($match->isUnknown());
        $this->assertSame(0, $match->confidence);
    }

    public function test_a_driver_only_intent_does_not_win_for_a_client_role(): void
    {
        // "licencia" es un vocablo débil (peso 1) de DOCUMENTOS_CONDUCTOR,
        // que está scoped a 'conductor' — para un cliente, ese intent ni
        // siquiera entra en la comparación.
        $match = $this->detector()->detect('licencia', null, 'cliente');

        $this->assertNotSame('DOCUMENTOS_CONDUCTOR', $match->code());
    }

    public function test_a_driver_role_can_match_the_driver_only_intent(): void
    {
        $match = $this->detector()->detect('qué documentos necesito para ser conductor', null, 'conductor');

        $this->assertSame('DOCUMENTOS_CONDUCTOR', $match->code());
    }

    public function test_the_pending_menu_context_takes_priority_over_keywords(): void
    {
        // El contexto tiene prioridad (pedido explícito del usuario, sección
        // 8): si el bot mostró un menú y el usuario responde solo "3", eso
        // vale como haber elegido esa opción, sin correr el reconocimiento
        // genérico de palabras clave.
        $menuIntent = ChatbotIntent::query()->where('code', 'SOPORTE')->firstOrFail();
        $conversation = ChatbotConversation::create([
            'phone' => '+593991111111',
            'pending_intent' => 'AWAITING_MENU_CHOICE',
            'context' => ['menu_options' => ['REGISTRO', 'INICIAR_SESION', 'SOPORTE']],
        ]);

        $match = $this->detector()->detect('3', $conversation, null);

        $this->assertSame('SOPORTE', $match->code());
        $this->assertSame(100, $match->confidence);
        $this->assertTrue($match->fromContext);
        $this->assertTrue($match->intent->is($menuIntent));
    }

    public function test_the_pending_menu_context_also_matches_by_label_text(): void
    {
        $conversation = ChatbotConversation::create([
            'phone' => '+593991111112',
            'pending_intent' => 'AWAITING_MENU_CHOICE',
            'context' => ['menu_options' => ['REGISTRO', 'SOPORTE']],
        ]);

        $match = $this->detector()->detect('quiero hablar con soporte', $conversation, null);

        $this->assertSame('SOPORTE', $match->code());
        $this->assertTrue($match->fromContext);
    }

    public function test_without_a_pending_menu_the_context_check_is_skipped(): void
    {
        $conversation = ChatbotConversation::create([
            'phone' => '+593991111113',
            'pending_intent' => null,
        ]);

        $match = $this->detector()->detect('hola', $conversation, null);

        $this->assertSame('SALUDO', $match->code());
        $this->assertFalse($match->fromContext);
    }
}
