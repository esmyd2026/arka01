import { reactive } from 'vue';

/**
 * Estado global de "carrera entrante" para el conductor (pedido explícito
 * del usuario: que ocupe media pantalla, no un renglón más en una lista, sin
 * importar en qué pantalla esté). Cola simple por si llegara una segunda
 * mientras la primera sigue en pantalla — se muestran de a una, en orden.
 */
export const incomingRideRequestState = reactive({
    queue: [],
});

export function pushIncomingRideRequest(request) {
    // Evita duplicar si el mismo evento llega dos veces (reconexión de Echo).
    if (incomingRideRequestState.queue.some((r) => r.id === request.id)) return false;
    incomingRideRequestState.queue.push(request);

    return true;
}

export function dismissIncomingRideRequest(id) {
    incomingRideRequestState.queue = incomingRideRequestState.queue.filter((r) => r.id !== id);
}

// El polling global es la fuente de verdad de las solicitudes todavía
// respondibles. Limpia tarjetas viejas si el POST lo hizo esta misma pestaña
// (los eventos `toOthers()` no regresan al navegador que respondió).
export function reconcileIncomingRideRequests(validIds) {
    const valid = validIds instanceof Set ? validIds : new Set(validIds);
    incomingRideRequestState.queue = incomingRideRequestState.queue.filter((request) => valid.has(request.id));
}
