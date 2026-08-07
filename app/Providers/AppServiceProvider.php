<?php

namespace App\Providers;

use App\Listeners\EnforceSingleActiveSession;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sesión única por cuenta (consideración de seguridad agregada al
        // alcance): se engancha al evento Login para no duplicar la
        // validación en cada controlador de login (contraseña y Google).
        Event::listen(Login::class, EnforceSingleActiveSession::class);
    }
}
