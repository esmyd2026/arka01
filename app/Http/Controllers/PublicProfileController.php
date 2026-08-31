<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Profile\PublicProfileFinder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lógica real en App\Services\Profile\PublicProfileFinder (roadmap app
 * móvil, "full backend").
 */
class PublicProfileController extends Controller
{
    public function __construct(private readonly PublicProfileFinder $profileFinder) {}

    /**
     * User-agents conocidos de rastreadores que arman una tarjeta de vista
     * previa al compartir un enlace (pedido explícito del usuario: "que el
     * mensaje a compartir por WhatsApp vaya el logo o perfil... algo
     * profesional"). Ninguno ejecuta JavaScript, así que las etiquetas
     * <meta og:*> que Profile/Show.vue agrega con Inertia<Head> nunca les
     * llegarían — esta app es una SPA sin SSR configurado.
     */
    private const LINK_PREVIEW_BOTS = '/WhatsApp|facebookexternalhit|Facebot|Twitterbot|LinkedInBot|Slackbot|TelegramBot|Discordbot/i';

    /**
     * Perfil público (sección 3.6): visible para cualquier usuario logueado,
     * no hace falta compartir flota — es justamente lo que permite evaluar a
     * alguien que todavía no conocés (un conductor público, o un cliente que
     * te invitó de la nada) antes de aceptar o invitar.
     */
    public function show(Request $request, User $user): Response|View
    {
        $data = $this->profileFinder->forUser($user, $request->user());

        // Para el puñado de rastreadores de vista previa, se sirve una
        // página mínima aparte con las etiquetas correctas (sin pasar por
        // Inertia, que no las mostraría a tiempo) — cualquier persona real
        // sigue viendo la app normal de siempre, esto nunca la reemplaza.
        if (preg_match(self::LINK_PREVIEW_BOTS, $request->userAgent() ?? '')) {
            return view('profile-preview', [
                'title' => "{$user->full_name} — Arka01",
                // Copia de llamada a la acción (pedido explícito del usuario:
                // "un mensaje con llamada a la accion... unete y haz que la
                // movilidad sea ahora mas segura") — misma copia que usa
                // Profile/Show.vue para la sesión con Inertia; esta vista
                // aparte solo existe para el rastreador de WhatsApp, que
                // nunca manda cookies de sesión.
                'description' => ($data['isDriver'] ? 'Conductor' : 'Cliente').' en Arka01'
                    .($data['reviewCount'] > 0 ? " · ★ {$data['averageRating']}" : '')
                    .($data['trustIndex'] ? " · Índice de confianza {$data['trustIndex']['score']}%" : '')
                    .' — únase y hagamos que la movilidad sea más segura en Ecuador.',
                'image' => $user->avatar_url && ! str_starts_with($user->avatar_url, 'http')
                    ? url($user->avatar_url)
                    : ($user->avatar_url ?? asset('icons/icon.svg')),
                'url' => $data['profileUrl'],
            ]);
        }

        return Inertia::render('Profile/Show', $data);
    }
}
