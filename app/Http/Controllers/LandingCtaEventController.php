<?php

namespace App\Http\Controllers;

use App\Models\LandingCtaEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LandingCtaEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Cada salida del modal se mide por separado: avanzar al registro,
            // ir al acceso o cerrarlo. Así el administrador entiende la
            // decisión tomada sin identificar personalmente al visitante.
            'event' => ['required', 'in:impression,click,login,dismiss'],
            'target' => ['nullable', 'in:general,client,driver,cooperative'],
            'visitor_token' => ['required', 'uuid'],
            'interaction_token' => ['required', 'string', 'size:48'],
            'website' => ['nullable', 'string', 'max:100'],
            'automated' => ['nullable', 'boolean'],
            'path' => ['nullable', 'string', 'max:255'],
            'referrer' => ['nullable', 'url', 'max:1000'],
        ]);

        $issuedAt = (int) $request->session()->get('landing_cta_issued_at', 0);
        $expectedToken = (string) $request->session()->get('landing_cta_token', '');
        $age = now()->timestamp - $issuedAt;
        $userAgent = Str::lower((string) $request->userAgent());

        // Defensa silenciosa: el visitante real recibió el token al abrir la
        // portada y solo ve el modal después de unos segundos. Honeypot,
        // automatización declarada, agentes conocidos y solicitudes demasiado
        // rápidas se descartan sin contaminar el indicador del administrador.
        $looksAutomated = filled($validated['website'] ?? null)
            || (bool) ($validated['automated'] ?? false)
            || preg_match('/bot|crawler|spider|headless|lighthouse|slurp|facebookexternalhit|preview/', $userAgent);

        if ($looksAutomated
            || $expectedToken === ''
            || ! hash_equals($expectedToken, $validated['interaction_token'])
            || $issuedAt === 0
            || $age < 2
            || $age > 7200) {
            return response()->json(['recorded' => false]);
        }

        $eventKey = $validated['event'].':'.($validated['target'] ?? 'general');
        $recordedEvents = $request->session()->get('landing_cta_recorded_events', []);

        // Evita dobles clics, reintentos de red y componentes montados dos
        // veces. Una visita nueva sí puede generar una nueva conversión.
        if (in_array($eventKey, $recordedEvents, true)) {
            return response()->json(['recorded' => true]);
        }

        $hashKey = (string) config('app.key');
        $referrerHost = filled($validated['referrer'] ?? null)
            ? parse_url($validated['referrer'], PHP_URL_HOST)
            : null;

        LandingCtaEvent::query()->create([
            'user_id' => $request->user()?->id,
            'event_type' => $validated['event'],
            'target' => $validated['target'] ?? 'general',
            'visitor_hash' => hash_hmac('sha256', $validated['visitor_token'], $hashKey),
            'session_hash' => hash_hmac('sha256', $request->session()->getId(), $hashKey),
            'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), $hashKey) : null,
            'referrer_host' => is_string($referrerHost) ? Str::limit($referrerHost, 255, '') : null,
            'landing_path' => Str::limit($validated['path'] ?? '/', 255, ''),
        ]);

        $request->session()->put('landing_cta_recorded_events', [...$recordedEvents, $eventKey]);

        return response()->json(['recorded' => true]);
    }
}
