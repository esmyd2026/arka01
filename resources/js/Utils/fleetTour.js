/**
 * Pasos del tutorial guiado de "Mis flotas" (pedido explícito del usuario) —
 * compartido entre Fleet/List.vue (lo dispara solo, una vez, en la primera
 * flota) y Components/FleetRoster.vue (botón "Ver tutorial" manual, por
 * flota). Los ids son por flota (`fleet-search-card-${fleetId}`) porque
 * FleetRoster.vue se monta una vez POR CADA flota del cliente — sin el
 * sufijo, dos flotas en la misma pantalla generarían ids duplicados.
 */
export function fleetTourSteps(fleetId) {
    return [
        {
            element: `#fleet-search-card-${fleetId}`,
            popover: {
                title: 'Conductores de confianza',
                description: 'Agrega acá a los conductores con los que ya trabajas — así puedes pedirles una carrera directamente, con confianza.',
            },
        },
        {
            element: `#fleet-driver-search-${fleetId}`,
            popover: {
                title: 'Búscalo por nombre o usuario',
                description: 'Escribe su nombre, apellido, usuario (@) o código, y toca "Invitar" junto al resultado para sumarlo a esta flota.',
            },
        },
    ];
}
