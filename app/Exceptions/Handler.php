<?php

namespace App\Exceptions;

use App\Services\SystemEventLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // Laravel ignora los 404 por defecto (son rutina — bots probando URLs
        // al azar). Pero un 404 para un usuario YA autenticado casi siempre
        // significa algo real (un id que ya no existe, un enlace roto, una
        // fila que se movió de flota) — eso sí queremos verlo en el log,
        // con quién y en qué URL le pasó (trazabilidad).
        $this->reportable(function (NotFoundHttpException|ModelNotFoundException $e) {
            $user = request()->user();

            if ($user) {
                Log::warning('404 para un usuario autenticado.', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });

        // Monitoreo de errores en producción (gap identificado antes del
        // despliegue): sin esto, un error real en vivo solo se ve si alguien
        // va a mirar storage/logs a mano. Sentry queda apagado solo mientras
        // SENTRY_LARAVEL_DSN esté vacío en .env (mismo criterio que WhatsApp
        // y Google OAuth: una integración opcional no bloquea nada mientras
        // no esté configurada).
        $this->reportable(function (Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }

            // Monitoreo interno (roadmap de mejoras, sección 9): además de
            // Sentry (que necesita entrar a un panel aparte, y solo funciona
            // si está configurado), esto queda visible desde
            // /admin/monitoreo sin ninguna integración externa. Este closure
            // ya solo se dispara para excepciones "de verdad" — Laravel
            // filtra antes las rutinarias (ValidationException,
            // AuthenticationException, HTTP 404/403, etc.). Envuelto en su
            // propio try/catch: si la excepción original fue justo una falla
            // de base de datos, este INSERT fallaría también — no puede
            // tumbar el reporte de errores en sí.
            try {
                SystemEventLogger::log(
                    eventType: 'unhandled_exception',
                    module: 'backend',
                    message: $e->getMessage() ?: $e::class,
                    severity: 'critical',
                    context: ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()],
                    userId: request()->user()?->id,
                );
            } catch (Throwable) {
                // Nada más que hacer acá — ya quedó en storage/logs y,
                // si está configurado, en Sentry.
            }
        });
    }

    /**
     * Bug real reportado por el usuario ("el botón Volver al inicio no hace
     * nada"): Inertia muestra CUALQUIER respuesta que no sea una página
     * Inertia válida (como la pantalla 419 de resources/views/errors/,
     * renderizada en HTML normal) adentro de un `<iframe sandbox="allow-
     * scripts">` — sin `allow-top-navigation`, así que ni con
     * `target="_top"` el navegador deja navegar la ventana real desde ese
     * enlace; el clic no hace nada porque el propio navegador lo bloquea,
     * no por nada que la app pudiera controlar del lado del botón. La forma
     * correcta (documentada por Inertia) es no dejar que un 419 llegue a
     * mostrarse ahí: se responde con un redirect normal, que Inertia sigue
     * como una navegación más — vuelve a la página anterior (y si la sesión
     * también quedó inválida, de ahí sigue derecho al login solo, sin CSRF
     * de por medio porque es un GET).
     */
    public function render($request, Throwable $e)
    {
        if ($e instanceof TokenMismatchException && $request->header('X-Inertia')) {
            return back()->with('status', 'La página expiró — vuelva a intentarlo.');
        }

        // Mismo problema que el 419 de arriba, pero con el 429 que devuelve
        // el middleware `throttle` (ride-requests.store, radio.invitation.join,
        // etc. — cualquier ruta con ese middleware): bug real reportado por
        // el usuario, "Volver al inicio" atrapado sin funcionar tras pedir
        // varias carreras seguidas o unirse repetido a un canal de radio.
        if ($e instanceof ThrottleRequestsException && $request->header('X-Inertia')) {
            return back()->with('status', 'Demasiados intentos. Espere un momento antes de volver a intentarlo.');
        }

        // Una página HTML de error dentro de una visita Inertia termina en
        // su visor aislado, donde el navegador bloquea la navegación del
        // botón "Volver al inicio". Esta respuesta especial ordena a
        // Inertia navegar la ventana real y evita que el usuario quede preso
        // en el error, aunque haya llegado desde una redirección encadenada.
        if (($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException)
            && $request->header('X-Inertia')) {
            return Inertia::location(url('/'));
        }

        return parent::render($request, $e);
    }
}
