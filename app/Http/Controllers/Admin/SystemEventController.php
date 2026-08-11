<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Monitoreo (pedido explícito del usuario, roadmap de
 * mejoras sección 9): "poder detectar problemas importantes sin revisar
 * directamente logs del servidor" — ver App\Services\SystemEventLogger para
 * dónde se llena esta tabla.
 */
class SystemEventController extends Controller
{
    public function index(Request $request): Response
    {
        $events = SystemEvent::query()
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->string('module')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->string('q')->toString();
                $q->where(fn ($q) => $q->where('event_type', 'like', "%{$search}%")->orWhere('message', 'like', "%{$search}%"));
            })
            ->with('user')
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Admin/Monitoring/Index', [
            'events' => $events,
            'filters' => $request->only(['module', 'severity', 'status', 'from', 'to', 'q']),
            // Módulos que existen de verdad hoy (no una lista fija en
            // código): si mañana se suma un módulo nuevo al logger, aparece
            // solo en el filtro.
            'modules' => SystemEvent::query()->distinct()->orderBy('module')->pluck('module'),
        ]);
    }

    /**
     * Triage simple (pedido explícito del usuario: "estados" para los
     * eventos) — un admin marca que ya se ocupó de esto, sin borrar el
     * registro (sigue sirviendo de historial).
     */
    public function markResolved(SystemEvent $systemEvent): RedirectResponse
    {
        $systemEvent->update(['status' => 'resolved']);

        return back();
    }
}
