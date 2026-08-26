<?php

namespace App\Services;

/**
 * Pedido explícito del usuario: "en el admin permiteme en el modulo de
 * sistema de habilitar o no estas opciones del menu tanto las del conductor
 * como las del cliente." Lista curada de los accesos rápidos de
 * `quickLinks` (resources/js/Layouts/AuthenticatedLayout.vue) que tiene
 * sentido dejar apagar desde el admin — solo los exclusivos de un rol
 * (driverOnly/clientOnly ahí). Quedan afuera a propósito los universales
 * (Contactos de confianza, Centro de ayuda, Cupones, Encuesta) y los de
 * cooperativa: no es lo que se pidió, y alguno de esos es demasiado crítico
 * para dejarlo apagable sin querer.
 *
 * Única fuente de verdad para Admin\SystemController (arma la lista de
 * checkboxes) — el `route` de cada renglón es la misma key que
 * AuthenticatedLayout.vue usa para filtrar contra `disabledQuickLinks`
 * (ver HandleInertiaRequests::share()), así que un cambio acá no tiene
 * efecto real hasta que el mismo `route` también exista en ese array del
 * frontend.
 */
class QuickLinkRegistry
{
    public const ITEMS = [
        'driver.invitations.index' => ['label' => 'Mis clientes de confianza', 'group' => 'conductor'],
        'express-routes.available' => ['label' => 'Expresos disponibles', 'group' => 'conductor'],
        'driver.plan.edit' => ['label' => 'Mi plan de conductor', 'group' => 'conductor'],
        'van-trips.index' => ['label' => 'Mis rutas y turismo', 'group' => 'conductor'],
        'cooperatives.index' => ['label' => 'Cooperativas verificadas', 'group' => 'cliente'],
        'ride-requests.create' => ['label' => 'Pedir una carrera', 'group' => 'cliente'],
        'directory.index' => ['label' => 'Directorio de conductores', 'group' => 'cliente'],
        'express-routes.index' => ['label' => 'Mis Expresos', 'group' => 'cliente'],
        'client.plan.edit' => ['label' => 'Mi plan de cliente', 'group' => 'cliente'],
        'van-trips.browse' => ['label' => 'Rutas y Turismo', 'group' => 'cliente'],
    ];

    /**
     * @param  array<int, string>  $disabledRoutes
     * @return array<int, array{route: string, label: string, group: string, enabled: bool}>
     */
    public static function withState(array $disabledRoutes): array
    {
        return collect(self::ITEMS)
            ->map(fn (array $item, string $route) => [
                'route' => $route,
                'label' => $item['label'],
                'group' => $item['group'],
                'enabled' => ! in_array($route, $disabledRoutes, true),
            ])
            ->values()
            ->all();
    }
}
