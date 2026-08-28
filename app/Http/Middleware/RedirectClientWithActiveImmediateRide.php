<?php

namespace App\Http\Middleware;

use App\Models\Ride;
use App\Models\RideRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectClientWithActiveImmediateRide
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isClient() || ! $request->isMethod('GET') || $request->routeIs(['rides.*', 'radio.*'])) {
            return $next($request);
        }

        $hasPending = RideRequest::query()->where('client_user_id', $user->id)
            ->where('is_scheduled', false)->whereIn('status', ['pending', 'negotiating', 'waiting'])->exists();
        // Cuando una reserva programada ya comenzó deja de ser futura: es
        // una carrera activa normal y también bloquea la navegación.
        $hasActiveRide = Ride::query()->where('client_user_id', $user->id)->where('status', 'in_progress')->exists();

        if ($hasPending || $hasActiveRide) {
            return redirect()->route('rides.index')->with('status', 'Tiene una carrera inmediata activa. Finalícela o cancélela antes de continuar.');
        }

        return $next($request);
    }
}
