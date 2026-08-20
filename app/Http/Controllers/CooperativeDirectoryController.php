<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Services\CooperativeReputation;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CooperativeDirectoryController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits, private readonly CooperativeReputation $reputation) {}

    public function index(Request $request): Response
    {
        $cooperatives = Cooperative::query()
            ->where('status', 'approved')
            ->whereNull('suspended_at')
            ->with('city')
            ->withCount(['activeDriverMemberships', 'clientLinks'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = trim($request->string('q')->toString());
                $query->where(fn ($query) => $query->where('name', 'like', "%{$term}%")
                    ->orWhere('legal_name', 'like', "%{$term}%")
                    ->orWhere('geographic_coverage', 'like', "%{$term}%"));
            })
            ->when($request->filled('city_id'), fn ($query) => $query->where('city_id', $request->integer('city_id')))
            ->orderByDesc('declared_unit_count')
            ->paginate(12)
            ->withQueryString();

        $attachedIds = $request->user()?->isClient()
            ? ClientCooperative::query()->where('client_user_id', $request->user()->id)->pluck('cooperative_id')
            : collect();

        $cooperatives->through(function (Cooperative $cooperative) use ($attachedIds) {
            $reputation = $this->reputation->summary($cooperative);

            return [
                'id' => $cooperative->id,
                'name' => $cooperative->name,
                'logo_url' => $cooperative->logo_url,
                'city' => $cooperative->city?->name,
                'province' => $cooperative->province,
                'coverage' => $cooperative->geographic_coverage,
                'operating_hours' => $cooperative->operating_hours,
                'driver_count' => $cooperative->active_driver_memberships_count,
                'unit_count' => $cooperative->declared_unit_count,
                'client_count' => $cooperative->client_links_count,
                'completed_rides' => $reputation['completed_rides'],
                'average_rating' => $reputation['average_rating'],
                'review_count' => $reputation['review_count'],
                'is_attached' => $attachedIds->contains($cooperative->id),
            ];
        });

        return Inertia::render('Cooperative/Directory', [
            'cooperatives' => $cooperatives,
            'filters' => $request->only(['q', 'city_id']),
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Cooperative $cooperative): Response
    {
        $canPreview = $cooperative->isApproved()
            || $request->user()?->isAdmin()
            || $request->user()?->cooperative?->is($cooperative);
        abort_unless($canPreview, 404);

        $cooperative->load('city');
        $summary = $this->reputation->summary($cooperative);

        return Inertia::render('Cooperative/Show', [
            'cooperative' => $cooperative,
            'reputation' => $summary,
            'drivers' => $this->reputation->drivers($cooperative),
            'reviews' => $this->reputation->recentReviews($cooperative),
            'isAttached' => $request->user()?->isClient()
                ? ClientCooperative::query()->where('client_user_id', $request->user()->id)->where('cooperative_id', $cooperative->id)->exists()
                : false,
        ]);
    }

    public function attach(Request $request, Cooperative $cooperative): RedirectResponse
    {
        abort_unless($request->user()->isClient(), 403);
        abort_unless($cooperative->isApproved(), 404);

        $limits = $this->planLimits->forClient($request->user());
        $current = ClientCooperative::query()->where('client_user_id', $request->user()->id)->count();

        if ($limits['max_cooperatives'] !== null && $current >= $limits['max_cooperatives']) {
            throw ValidationException::withMessages([
                'cooperative' => "Su plan permite guardar hasta {$limits['max_cooperatives']} cooperativa(s).",
            ]);
        }

        ClientCooperative::query()->firstOrCreate([
            'client_user_id' => $request->user()->id,
            'cooperative_id' => $cooperative->id,
        ]);

        return back()->with('status', 'Cooperativa agregada a su red de confianza.');
    }

    public function detach(Request $request, Cooperative $cooperative): RedirectResponse
    {
        abort_unless($request->user()->isClient(), 403);

        ClientCooperative::query()
            ->where('client_user_id', $request->user()->id)
            ->where('cooperative_id', $cooperative->id)
            ->delete();

        return back()->with('status', 'Cooperativa retirada de su red.');
    }
}
