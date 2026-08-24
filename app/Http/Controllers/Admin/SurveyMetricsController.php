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

        return [
            'total' => $total,
            'mainProblem' => ($mainProblem && $mainProblem['count'] > 0) ? $mainProblem : null,
            // Dos indicadores destacados pedidos explícitamente por el
            // usuario ("situación actual de la inseguridad del ecuador,
            // las horas de la noche") — se calculan como el % que cayó en
            // alguna de las opciones "preocupantes" de esa pregunta, no
            // solo la opción más votada, porque acá interesa el tamaño del
            // grupo en riesgo, no cuál matiz exacto eligieron.
            'nightSafety' => $this->concerningPercent($responses, $total, SurveyQuestions::NIGHT_SAFETY_QUESTION_KEY, SurveyQuestions::NIGHT_SAFETY_CONCERNING_OPTIONS),
            'insecurityPerception' => $this->concerningPercent($responses, $total, SurveyQuestions::INSECURITY_PERCEPTION_QUESTION_KEY, SurveyQuestions::INSECURITY_PERCEPTION_HIGH_OPTIONS),
            'questions' => $questionBreakdown,
        ];
    }

    /**
     * @param  array<int, string>  $concerningOptionKeys
     * @return array{count: int, percent: float}
     */
    private function concerningPercent(Collection $responses, int $total, string $questionKey, array $concerningOptionKeys): array
    {
        $count = $responses->filter(
            fn (SurveyResponse $response) => in_array($response->answers[$questionKey] ?? null, $concerningOptionKeys, true)
        )->count();

        return [
            'count' => $count,
            'percent' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
        ];
    }
}
