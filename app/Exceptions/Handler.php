<?php

namespace App\Exceptions;

use App\Services\SystemEventLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
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
}
