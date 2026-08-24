<?php

namespace App\Http\Controllers;

use App\Models\SurveyResponse;
use App\Services\SurveyQuestions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Encuesta corta de conductor/pasajero (pedido explícito del usuario:
 * "aterrizar el problema actual de cada individuo... identificar si arka01
 * esta abordando todo"). Pública, sin necesidad de sesión ("no necesita
 * tener usuario para hacer la encuesta") — accesible desde el Home y desde
 * el login por igual. Los resultados se ven agregados en
 * Admin\SurveyMetricsController.
 */
class SurveyController extends Controller
{
    /**
     * Sin `?rol=`, el propio Vue muestra el selector "¿Sos conductor o
     * pasajero?" — recién con el rol elegido se le mandan las preguntas.
     */
    public function show(Request $request): Response
    {
        $role = $request->query('rol');
        $role = in_array($role, SurveyQuestions::ROLES, true) ? $role : null;

        return Inertia::render('Survey/Show', [
            'role' => $role,
            'questions' => $role ? SurveyQuestions::forRole($role) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Primero solo el rol: las reglas de `answers.*` dependen de qué
        // preguntas/opciones tiene ESE rol (ver SurveyQuestions), así que
        // hace falta saberlo antes de poder armar el resto de las reglas.
        ['role' => $role] = $request->validate([
            'role' => ['required', Rule::in(SurveyQuestions::ROLES)],
        ]);

        $questions = SurveyQuestions::forRole($role);

        // Cada pregunta valida contra SUS PROPIAS opciones — arma las
        // reglas dinámicamente en vez de una lista fija, para que agregar o
        // cambiar una pregunta en SurveyQuestions no requiera tocar esto.
        // Las preguntas `multi` (pedido explícito del usuario: "en las que
        // puede existir varios problemas que se junten") reciben un array
        // de opciones en vez de una sola.
        $rules = ['answers' => ['required', 'array:'.implode(',', array_column($questions, 'key'))]];
        foreach ($questions as $question) {
            $optionKeys = array_column($question['options'], 'key');

            if ($question['multi'] ?? false) {
                $rules["answers.{$question['key']}"] = ['required', 'array', 'min:1'];
                $rules["answers.{$question['key']}.*"] = [Rule::in($optionKeys)];
            } else {
                $rules["answers.{$question['key']}"] = ['required', Rule::in($optionKeys)];
            }
        }
        $validated = $request->validate($rules);

        SurveyResponse::query()->create([
            'role' => $role,
            'user_id' => $request->user()?->id,
            'answers' => $validated['answers'],
        ]);

        return back()->with('status', '¡Gracias por contarnos tu experiencia!');
    }
}
