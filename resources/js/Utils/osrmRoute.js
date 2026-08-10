// Trazado real del recorrido entre dos puntos (sección 9.3): OSRM, gratis,
// sin API key — se mantiene Leaflet/OpenStreetMap en vez de Google Maps para
// no generar costos. Compartido entre las pantallas que muestran un mapa con
// origen y destino (Pedir carrera, Expresos, Viajes en VAN).
export async function fetchOsrmRoute(originLat, originLng, destinationLat, destinationLng) {
    try {
        // fetch() nativo, NO window.axios (bug real reportado, veía errores de
        // CORS en la consola al elegir destino): apenas Echo se conecta, mete
        // un header "X-Socket-Id" en TODAS las peticiones de window.axios —
        // necesario para que ->toOthers() funcione contra nuestro propio
        // backend, pero un servidor externo como OSRM no lo tiene permitido
        // en su Access-Control-Allow-Headers, así que el preflight fallaba y
        // el trazado nunca llegaba a pedirse de verdad.
        const url = `https://router.project-osrm.org/route/v1/driving/${originLng},${originLat};${destinationLng},${destinationLat}?overview=full&geometries=geojson`;
        const response = await fetch(url);
        const data = await response.json();
        const coords = data?.routes?.[0]?.geometry?.coordinates ?? [];
        return coords.map(([lng, lat]) => ({ lat, lng }));
    } catch {
        // Si el servicio gratuito de ruteo no responde, no rompemos el flujo —
        // simplemente no se ve la línea del recorrido.
        return [];
    }
}
