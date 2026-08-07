<?php

namespace App\Exceptions;

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
        });
    }
}
