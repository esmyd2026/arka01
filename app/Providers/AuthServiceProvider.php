<?php

namespace App\Providers;

use App\Models\ExpressRoute;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Policies\ExpressRoutePolicy;
use App\Policies\FleetInvitationPolicy;
use App\Policies\FleetPolicy;
// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Fleet::class => FleetPolicy::class,
        FleetInvitation::class => FleetInvitationPolicy::class,
        ExpressRoute::class => ExpressRoutePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
