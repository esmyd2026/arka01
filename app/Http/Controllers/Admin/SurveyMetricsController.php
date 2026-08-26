<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SurveyResponse;
use App\Services\SurveyQuestions;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel de indicadores de la encuesta corta de conductor/pasajero (pedido
 * explícito del usuario: "indicadores que me ayuden a determinar decisiones
 * y fortalecer que estoy cubriendo un problema"). Mismo estilo que
 * Admin\MetricsController: tarjetas + tablas simples, sin librería de
 * gráficos — a este volumen alcanza con contar en PHP, sin agregación SQL
 * sobre la columna `answers` (json).
 */
class SurveyMetricsController extends Controller
{
    /**
     * Pedido explícito del usuario ("un analisis dinamico... algo bien
     * chevere para yo ir postiando"): con una sola respuesta, "el 100% de
     * las cooperativas opinan..." sería engañoso — se pisa el pie a
     * propósito hasta juntar una muestra mínima razonable.
     */
    private const MIN_SAMPLE_FOR_INSIGHTS = 5;

    /**
     * Artículo/sustantivo para insertar en medio de una oración ("de LOS
     * PASAJEROS encuestados...") y la frase de "seguridad de noche", que
     * cambia de sujeto según el rol (uno mismo vs. su flota).
     */
    private const ROLE_META = [
        'pasajero' => ['article' => 'los', 'noun' => 'pasajeros', 'nightSafetyText' => 'no se sienten seguros viajando de noche'],
        'conductor' => ['article' => 'los', 'noun' => 'conductores', 'nightSafetyText' => 'no se sienten seguros trabajando de noche'],
        'cooperativa' => ['article' => 'las', 'noun' => 'cooperativas', 'nightSafetyText' => 'consideran insegura la operación nocturna de sus conductores'],
    ];

    /**
     * Además de "mayor problema", inseguridad del país y seguridad de
     * noche (siempre presentes, ver insightsFor()), un par de preguntas
     * curadas por rol que también dan un buen dato para compartir —
     * elegidas por ser las más "citables" de cada encuesta (comisión,
     * confianza, interés en la propuesta de Arka01), no todas las
     * preguntas.
     */
    private const EXTRA_INSIGHT_QUESTIONS = [
        'pasajero' => [
            ['questionKey' => 'confianza_identidad', 'optionKeys' => ['no_confio', 'dudas'], 'emoji' => '🔍', 'template' => 'no confían (o tienen dudas de) que las apps de transporte verifiquen bien a sus conductores'],
            ['questionKey' => 'interes_confianza', 'optionKeys' => ['me_encantaria'], 'emoji' => '🤝', 'template' => 'les encantaría poder elegir viajar solo con conductores conocidos o recomendados por gente de confianza'],
        ],
        'conductor' => [
            ['questionKey' => 'comision_actual', 'optionKeys' => ['muy_alta', 'alta'], 'emoji' => '💸', 'template' => 'consideran alta o muy alta la comisión que les cobran las plataformas que usan hoy'],
            ['questionKey' => 'interes_sin_comision', 'optionKeys' => ['me_interesa_mucho'], 'emoji' => '🚫', 'template' => 'les interesa mucho trabajar con una plataforma que no les cobre comisión por carrera'],
            ['questionKey' => 'interes_flota_propia', 'optionKeys' => ['me_interesa'], 'emoji' => '🚗', 'template' => 'les interesa tener sus propios clientes de confianza en vez de depender de una bolsa general de pasajeros'],
        ],
        'cooperativa' => [
            ['questionKey' => 'confianza_control', 'optionKeys' => ['no_confio', 'dudas'], 'emoji' => '🔍', 'template' => 'no confían (o tienen dudas de) tener control real y visibilidad de lo que hacen sus conductores en la calle'],
            ['questionKey' => 'interes_panel', 'optionKeys' => ['me_interesa_mucho'], 'emoji' => '📲', 'template' => 'les interesa mucho contar con un panel propio para gestionar y dar visibilidad a su flota'],
        ],
    ];

    public function index(): Response
    {
        $byRole = collect(SurveyQuestions::ROLES)->mapWithKeys(
            fn (string $role) => [$role => $this->roleBreakdown($role)]
        );

        return Inertia::render('Admin/SurveyMetrics', [
            'roles' => $byRole,
        ]);
    }

    /**
     * @return array{total: int, mainProblem: array{key: string, label: string, count: int, percent: float}|null, nightSafety: array{count: int, percent: float}, insecurityPerception: array{count: int, percent: float}, questions: Collection}
     */
    private function roleBreakdown(string $role): array
    {
        $responses = SurveyResponse::query()->where('role', $role)->get();
        $total = $responses->count();
        $questions = SurveyQuestions::forRole($role);

        $questionBreakdown = collect($questions)->map(function (array $question) use ($responses, $total) {
            // Preguntas `multi` (pedido explícito del usuario: "en las que
            // puede existir varios problemas que se junten") guardan un
            // array de opciones por respuesta en vez de una sola — se
            // aplanan todas antes de contar, así el mismo encuestado puede
            // sumar en varias opciones a la vez.
            $counts = ($question['multi'] ?? false)
                ? $responses->flatMap(fn (SurveyResponse $response) => (array) ($response->answers[$question['key']] ?? []))->countBy()
                : $responses->countBy(fn (SurveyResponse $response) => $response->answers[$question['key']] ?? null);

            $options = collect($question['options'])->map(function (array $option) use ($counts, $total) {
                $count = $counts[$option['key']] ?? 0;

                return [
                    'key' => $option['key'],
                    'label' => $option['label'],
                    'count' => $count,
                    'percent' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                ];
            });

            return [
                'key' => $question['key'],
                'text' => $question['text'],
                'multi' => $question['multi'] ?? false,
                'options' => $options,
            ];
        });

        // El "problema #1 reportado" (pedido explícito del usuario) es la
        // opción más elegida de la pregunta MAIN_PROBLEM_QUESTION_KEY —
        // el indicador más directo para decidir qué atacar primero.
        $mainProblemQuestion = $questionBreakdown->firstWhere('key', SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY);
        $mainProblem = $mainProblemQuestion
            ? $mainProblemQuestion['options']->sortByDesc('count')->first()
            : null;

        $nightSafety = $this->optionsPercent($responses, $total, SurveyQuestions::NIGHT_SAFETY_QUESTION_KEY, SurveyQuestions::NIGHT_SAFETY_CONCERNING_OPTIONS);
        $insecurityPerception = $this->optionsPercent($responses, $total, SurveyQuestions::INSECURITY_PERCEPTION_QUESTION_KEY, SurveyQuestions::INSECURITY_PERCEPTION_HIGH_OPTIONS);
        $mainProblemFinal = ($mainProblem && $mainProblem['count'] > 0) ? $mainProblem : null;

        return [
            'total' => $total,
            'mainProblem' => $mainProblemFinal,
            // Dos indicadores destacados pedidos explícitamente por el
            // usuario ("situación actual de la inseguridad del ecuador,
            // las horas de la noche") — se calculan como el % que cayó en
            // alguna de las opciones "preocupantes" de esa pregunta, no
            // solo la opción más votada, porque acá interesa el tamaño del
            // grupo en riesgo, no cuál matiz exacto eligieron.
            'nightSafety' => $nightSafety,
            'insecurityPerception' => $insecurityPerception,
            'questions' => $questionBreakdown,
            // Pedido explícito del usuario: "un analisis dinamico, que diga
            // 10 de tanto clientes opinan que..." — frases listas para
            // copiar y publicar, armadas con los mismos números de arriba
            // (nunca inventadas), ver insightsFor().
            'insights' => $this->insightsFor($role, $responses, $total, $mainProblemFinal, $nightSafety, $insecurityPerception),
        ];
    }

    /**
     * @param  array{count: int, percent: float}  $nightSafety
     * @param  array{count: int, percent: float}  $insecurityPerception
     * @return array<int, array{emoji: string, text: string}>
     */
    private function insightsFor(string $role, Collection $responses, int $total, ?array $mainProblem, array $nightSafety, array $insecurityPerception): array
    {
        if ($total < self::MIN_SAMPLE_FOR_INSIGHTS) {
            return [];
        }

        $meta = self::ROLE_META[$role];
        $subject = "{$meta['article']} {$meta['noun']}";

        $insights = [];

        if ($mainProblem) {
            $insights[] = [
                'emoji' => '📊',
                'text' => "{$mainProblem['percent']}% de {$subject} encuestados ({$mainProblem['count']} de {$total}) dicen que su mayor problema es: \"{$mainProblem['label']}\".",
            ];
        }

        $insights[] = [
            'emoji' => '🇪🇨',
            'text' => "{$insecurityPerception['percent']}% de {$subject} encuestados ({$insecurityPerception['count']} de {$total}) perciben la inseguridad del país como alta o muy alta.",
        ];

        $insights[] = [
            'emoji' => '🌙',
            'text' => "{$nightSafety['percent']}% de {$subject} encuestados ({$nightSafety['count']} de {$total}) {$meta['nightSafetyText']}.",
        ];

        foreach (self::EXTRA_INSIGHT_QUESTIONS[$role] ?? [] as $definition) {
            $stat = $this->optionsPercent($responses, $total, $definition['questionKey'], $definition['optionKeys']);

            // Una pregunta sin ninguna respuesta en esas opciones no aporta
            // nada citable — se omite en vez de mostrar "0% de...".
            if ($stat['count'] === 0) {
                continue;
            }

            $insights[] = [
                'emoji' => $definition['emoji'],
                'text' => "{$stat['percent']}% de {$subject} encuestados ({$stat['count']} de {$total}) {$definition['template']}.",
            ];
        }

        return $insights;
    }

    /**
     * Cuenta cuántas respuestas cayeron en alguna de un grupo de opciones de
     * una pregunta de selección única — sirve tanto para agrupar opciones
     * "preocupantes" (nightSafety/insecurityPerception) como "positivas"
     * (los insights de interés en la propuesta, ver EXTRA_INSIGHT_QUESTIONS).
     *
     * @param  array<int, string>  $optionKeys
     * @return array{count: int, percent: float}
     */
    private function optionsPercent(Collection $responses, int $total, string $questionKey, array $optionKeys): array
    {
        $count = $responses->filter(
            fn (SurveyResponse $response) => in_array($response->answers[$questionKey] ?? null, $optionKeys, true)
        )->count();

        return [
            'count' => $count,
            'percent' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
        ];
    }
}
