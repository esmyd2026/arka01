<?php

namespace Tests\Feature;

use App\Models\SurveyResponse;
use App\Models\User;
use App\Services\SurveyQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Encuesta corta de conductor/pasajero (pedido explícito del usuario:
 * "no necesita tener usuario para hacer la encuesta") — pública, con o sin
 * sesión iniciada.
 */
class SurveyTest extends TestCase
{
    use RefreshDatabase;

    // Las preguntas `multi` (pedido explícito del usuario: "en las que
    // puede existir varios problemas que se junten") reciben un array de
    // opciones en vez de una sola — se arma acá para que este helper siga
    // sirviendo sin importar qué pregunta se marque `multi` más adelante, y
    // para cualquiera de los 3 roles (pasajero/conductor/cooperativa).
    private function answersFor(string $role): array
    {
        $answers = [];
        foreach (SurveyQuestions::forRole($role) as $question) {
            $answers[$question['key']] = ($question['multi'] ?? false)
                ? [$question['options'][0]['key']]
                : $question['options'][0]['key'];
        }

        return $answers;
    }

    private function passengerAnswers(): array
    {
        return $this->answersFor('pasajero');
    }

    public function test_the_show_page_returns_no_questions_without_a_role(): void
    {
        $response = $this->get(route('survey.show'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('role', null)->where('questions', null));
    }

    public function test_the_show_page_returns_the_right_questions_for_a_role(): void
    {
        $response = $this->get(route('survey.show', ['rol' => 'conductor']));

        $response->assertInertia(fn ($page) => $page
            ->where('role', 'conductor')
            ->has('questions', count(SurveyQuestions::forRole('conductor')))
        );
    }

    public function test_the_show_page_returns_the_right_questions_for_the_cooperative_role(): void
    {
        $response = $this->get(route('survey.show', ['rol' => 'cooperativa']));

        $response->assertInertia(fn ($page) => $page
            ->where('role', 'cooperativa')
            ->has('questions', count(SurveyQuestions::forRole('cooperativa')))
        );
    }

    public function test_an_invalid_role_in_the_query_string_is_ignored(): void
    {
        $response = $this->get(route('survey.show', ['rol' => 'gerente']));

        $response->assertInertia(fn ($page) => $page->where('role', null));
    }

    public function test_a_guest_can_submit_the_survey_without_an_account(): void
    {
        $response = $this->post(route('survey.store'), [
            'role' => 'pasajero',
            'answers' => $this->passengerAnswers(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('survey_responses', ['role' => 'pasajero', 'user_id' => null]);
    }

    public function test_a_logged_in_user_has_their_id_attached(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('survey.store'), [
            'role' => 'pasajero',
            'answers' => $this->passengerAnswers(),
        ]);

        $this->assertDatabaseHas('survey_responses', ['role' => 'pasajero', 'user_id' => $user->id]);
    }

    public function test_a_cooperative_can_submit_the_survey(): void
    {
        $response = $this->post(route('survey.store'), [
            'role' => 'cooperativa',
            'answers' => $this->answersFor('cooperativa'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('survey_responses', ['role' => 'cooperativa', 'user_id' => null]);
    }

    public function test_an_invalid_option_for_a_question_is_rejected(): void
    {
        $answers = $this->passengerAnswers();
        $answers['seguridad_actual'] = 'opcion_inventada';

        $this->post(route('survey.store'), ['role' => 'pasajero', 'answers' => $answers])
            ->assertSessionHasErrors('answers.seguridad_actual');

        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_a_multi_select_question_accepts_more_than_one_option(): void
    {
        $answers = $this->passengerAnswers();
        $answers[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY] = ['confianza', 'seguridad'];

        $response = $this->post(route('survey.store'), ['role' => 'pasajero', 'answers' => $answers]);

        $response->assertRedirect();
        $this->assertDatabaseHas('survey_responses', ['role' => 'pasajero']);
        $stored = SurveyResponse::query()->first();
        $this->assertSame(['confianza', 'seguridad'], $stored->answers[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY]);
    }

    public function test_an_invalid_option_inside_a_multi_select_answer_is_rejected(): void
    {
        $answers = $this->passengerAnswers();
        $answers[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY] = ['confianza', 'opcion_inventada'];

        $this->post(route('survey.store'), ['role' => 'pasajero', 'answers' => $answers])
            ->assertSessionHasErrors('answers.'.SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY.'.1');

        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_an_empty_multi_select_answer_is_rejected(): void
    {
        $answers = $this->passengerAnswers();
        $answers[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY] = [];

        $this->post(route('survey.store'), ['role' => 'pasajero', 'answers' => $answers])
            ->assertSessionHasErrors('answers.'.SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY);

        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_a_missing_answer_is_rejected(): void
    {
        $answers = $this->passengerAnswers();
        unset($answers[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY]);

        $this->post(route('survey.store'), ['role' => 'pasajero', 'answers' => $answers])
            ->assertSessionHasErrors('answers.'.SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY);
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $this->post(route('survey.store'), ['role' => 'gerente', 'answers' => []])
            ->assertSessionHasErrors('role');
    }

    public function test_a_regular_user_cannot_see_the_admin_survey_metrics(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.survey-metrics.index'))->assertForbidden();
    }

    public function test_the_admin_panel_breaks_down_answers_and_finds_the_main_problem(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $answersA = $this->passengerAnswers();
        $answersA[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY] = ['confianza'];
        $answersB = $this->passengerAnswers();
        $answersB[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY] = ['confianza'];
        $answersC = $this->passengerAnswers();
        $answersC[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY] = ['precio'];

        SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $answersA]);
        SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $answersB]);
        SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $answersC]);

        $response = $this->actingAs($admin)->get(route('admin.survey-metrics.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('roles.pasajero.total', 3)
            ->where('roles.pasajero.mainProblem.key', 'confianza')
            ->where('roles.pasajero.mainProblem.count', 2)
            ->where('roles.conductor.total', 0)
        );
    }

    public function test_a_respondent_who_picked_several_problems_counts_toward_each_one(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Un mismo encuestado marcó dos problemas a la vez — tiene que
        // sumar en las dos opciones, no repartirse entre ellas (a
        // diferencia de las preguntas de una sola respuesta).
        $answersA = $this->passengerAnswers();
        $answersA[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY] = ['confianza', 'precio'];
        $answersB = $this->passengerAnswers();
        $answersB[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY] = ['precio'];

        SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $answersA]);
        SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $answersB]);

        $response = $this->actingAs($admin)->get(route('admin.survey-metrics.index'));

        $response->assertInertia(function ($page) {
            $question = collect($page->toArray()['props']['roles']['pasajero']['questions'])
                ->firstWhere('key', SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY);
            $confianza = collect($question['options'])->firstWhere('key', 'confianza');
            $precio = collect($question['options'])->firstWhere('key', 'precio');

            $page->where('roles.pasajero.total', 2);
            $this->assertSame(1, $confianza['count']);
            $this->assertSame(2, $precio['count']);
        });
    }

    public function test_the_admin_panel_reports_night_safety_and_country_insecurity_indicators(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $answersA = $this->passengerAnswers();
        $answersA[SurveyQuestions::NIGHT_SAFETY_QUESTION_KEY] = 'muy_inseguro';
        $answersA[SurveyQuestions::INSECURITY_PERCEPTION_QUESTION_KEY] = 'muy_alta';
        $answersB = $this->passengerAnswers();
        $answersB[SurveyQuestions::NIGHT_SAFETY_QUESTION_KEY] = 'evito';
        $answersB[SurveyQuestions::INSECURITY_PERCEPTION_QUESTION_KEY] = 'baja';
        $answersC = $this->passengerAnswers();
        $answersC[SurveyQuestions::NIGHT_SAFETY_QUESTION_KEY] = 'muy_seguro';
        $answersC[SurveyQuestions::INSECURITY_PERCEPTION_QUESTION_KEY] = 'moderada';

        SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $answersA]);
        SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $answersB]);
        SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $answersC]);

        $response = $this->actingAs($admin)->get(route('admin.survey-metrics.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('roles.pasajero.nightSafety.count', 2)
            ->where('roles.pasajero.nightSafety.percent', 66.7)
            ->where('roles.pasajero.insecurityPerception.count', 1)
            ->where('roles.pasajero.insecurityPerception.percent', 33.3)
        );
    }

    /**
     * Pedido explícito del usuario: "un analisis dinamico, que diga 10 de
     * tanto clientes opinan que...... algo bien chevere para yo ir
     * postiando" — frases armadas con los mismos números ya probados
     * arriba, no un cálculo aparte.
     */
    public function test_insights_are_empty_below_the_minimum_sample(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // 4 respuestas, una menos que el mínimo de 5 (SurveyMetricsController::MIN_SAMPLE_FOR_INSIGHTS).
        for ($i = 0; $i < 4; $i++) {
            SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $this->passengerAnswers()]);
        }

        $response = $this->actingAs($admin)->get(route('admin.survey-metrics.index'));

        $response->assertInertia(fn ($page) => $page->where('roles.pasajero.insights', []));
    }

    public function test_insights_appear_once_the_minimum_sample_is_reached(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // 5 respuestas: 4 marcan "seguridad" como mayor problema, 3 dicen
        // que no confían en la verificación de conductores, ninguna elige
        // la opción aspiracional "me_encantaria" — para probar tanto que
        // aparece un insight con datos reales, como que uno sin ninguna
        // respuesta (0 de 5) se omite en vez de mostrar "0%".
        for ($i = 0; $i < 5; $i++) {
            $answers = $this->passengerAnswers();
            $answers[SurveyQuestions::MAIN_PROBLEM_QUESTION_KEY] = ['seguridad'];
            $answers['confianza_identidad'] = $i < 3 ? 'no_confio' : 'confio';
            $answers['interes_confianza'] = 'prefiero_cualquiera';
            SurveyResponse::query()->create(['role' => 'pasajero', 'answers' => $answers]);
        }

        $response = $this->actingAs($admin)->get(route('admin.survey-metrics.index'));

        $response->assertInertia(function ($page) {
            $insights = $page->toArray()['props']['roles']['pasajero']['insights'];
            $texts = collect($insights)->pluck('text');

            $this->assertTrue($texts->contains(fn ($text) => str_contains($text, '5 de 5') && str_contains($text, 'seguridad')));
            $this->assertTrue($texts->contains(fn ($text) => str_contains($text, '3 de 5') && str_contains($text, 'no confían')));
            $this->assertFalse($texts->contains(fn ($text) => str_contains($text, 'encantaría')));
        });
    }
}
