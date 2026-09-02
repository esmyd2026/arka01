// Capacidades del conductor (pedido explícito del usuario: "no duplicar la
// lógica de negocio en múltiples componentes") — mismo dato que arma
// App\Services\Driver\DriverAccessResolver, compartido en cualquier
// pantalla vía usePage().props.auth.driverAccess. Nunca null para un
// conductor autenticado; null para cualquier otro rol.

export const DRIVER_PERMISSION = {
    COOPERATIVE_RIDES_RECEIVE: 'cooperative.rides.receive',
    COOPERATIVE_RIDES_ACCEPT: 'cooperative.rides.accept',
    COOPERATIVE_RADIO_ACCESS: 'cooperative.radio.access',
    PRIVATE_CLIENTS_MANAGE: 'private.clients.manage',
    PRIVATE_FLEET_JOIN: 'private.fleet.join',
    PRIVATE_REQUESTS_RECEIVE: 'private.requests.receive',
};

export function hasPermission(access, permission) {
    return Boolean(access?.permissions?.includes(permission));
}

const TYPE_LABELS = {
    both: 'Cooperativa y acceso profesional',
    cooperative: 'Conductor de cooperativa',
    professional: 'Acceso profesional',
    basic: 'Conductor independiente',
};

export function driverAccessLabel(access) {
    return TYPE_LABELS[access?.type] ?? TYPE_LABELS.basic;
}
