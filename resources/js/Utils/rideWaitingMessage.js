// Mensaje de "buscando conductor..." de una solicitud pendiente (sección 13
// del documento de rediseño UX: "Buscando conductor..." / "Enviando
// solicitud a {nombre}..."). Extraído de Ride/Index.vue para reusarlo
// también en el paso de confirmación de Ride/Request.vue, sin duplicar la
// lógica en los dos lugares — recibe `nowMs` en vez de tener su propio reloj
// porque cada pantalla ya maneja el suyo (setInterval propio).
export function waitingMinutes(request, nowMs) {
    if (!request.requested_at) return 0;
    return Math.floor((nowMs - new Date(request.requested_at).getTime()) / 60000);
}

// Fix reportado por el usuario: acá decía siempre "buscando entre tus
// conductores disponibles" (plural) — confuso para una solicitud dirigida a
// UN conductor puntual, que no está "buscando entre" nadie más.
export function waitingMessage(request, nowMs) {
    const minutes = waitingMinutes(request, nowMs);
    if (request.status === 'negotiating') return null;

    if (request.driver) {
        if (minutes < 3) return `Esperando que ${request.driver.name} responda…`;
        return `${request.driver.name} todavía no respondió. Si quiere, suba su oferta o cancele y pruebe con otro.`;
    }

    if (minutes < 1) return 'Buscando entre sus conductores disponibles…';
    if (minutes < 3) return 'Avisamos a sus conductores — puede tardar un minuto en aparecer alguien.';
    return 'Todavía nadie respondió. Si quiere, suba su oferta para que sea más atractiva.';
}

// Despacho secuencial estilo Uber (pedido explícito del usuario): cuánto
// falta para que la oferta actual pase al siguiente candidato.
export function secondsLeft(request, nowMs) {
    if (!request.current_offer_expires_at) return null;
    return Math.max(0, Math.round((new Date(request.current_offer_expires_at).getTime() - nowMs) / 1000));
}
