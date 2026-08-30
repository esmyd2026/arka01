<?php

namespace App\Services;

/**
 * Pedido explícito del usuario: "en el admin permiteme en el modulo de
 * sistema de habilitar o no estas opciones del menu tanto las del conductor
 * como las del cliente." Lista curada de los accesos rápidos de
 * `quickLinks` (resources/js/Layouts/AuthenticatedLayout.vue) que tiene
 * sentido dejar apagar desde el admin. Incluye los exclusivos de conductor,
 * los exclusivos de cliente y los compartidos por ambos roles. Los accesos
 * operativos de cooperativa permanecen fuera de este control porque tienen
 * su propia navegación administrativa.
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
        'driver.profile.edit' => ['label' => 'Mi perfil de conductor', 'group' => 'conductor'],
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
        'trust-circle.index' => ['label' => 'Mi círculo de confianza', 'group' => 'ambos'],
        'trusted-contacts.index' => ['label' => 'Contactos de confianza', 'group' => 'ambos'],
        'support.index' => ['label' => 'Centro de ayuda', 'group' => 'ambos'],
        'coupons.index' => ['label' => 'Cupones y beneficios', 'group' => 'ambos'],
        'survey.show' => ['label' => 'Encuesta', 'group' => 'ambos'],
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
