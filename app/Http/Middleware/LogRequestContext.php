<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trazabilidad de logs: agrega contexto (quién, qué URL, un id único por
 * request) que Laravel adjunta automáticamente a TODA línea de log que se
 * escriba durante este request — la propia (Log::info/error de los
 * controladores) y la del framework (excepciones no atrapadas). Así, ante
 * cualquier error, se sabe de dónde vino sin tener que adivinar.
 */
class LogRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Context::add('request_id', (string) Str::uuid());
        Context::add('method', $request->method());
        Context::add('url', $request->fullUrl());
        Context::add('user_id', $request->user()?->id);

        return $next($request);
    }
}
