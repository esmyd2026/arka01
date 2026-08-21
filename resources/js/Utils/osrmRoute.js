// Trazado real del recorrido entre dos puntos (sección 9.3): OSRM, gratis,
// sin API key — se mantiene Leaflet/OpenStreetMap en vez de Google Maps para
// no generar costos. Compartido entre las pantallas que muestran un mapa con
// origen y destino (Pedir carrera, Expresos, Rutas y Turismo).
//
// Bug real detectado (probado a mano con dos rutas de Guayaquil contra este
// mismo servidor OSRM): el precio se calculaba con la distancia en línea
// recta (Haversine), pero el mapa siempre mostró la ruta real manejando —
// en una de las pruebas la ruta real dio 4.9 km contra 1.6 km en línea
// recta, más del triple. Ahora se devuelve también `distanceKm` (de la
// misma respuesta de OSRM, antes se descartaba) para que quien haga el
// pedido de carrera pueda usar la distancia real, no la de línea recta —
// ver Ride/Request.vue y RideRequestController::store().
function decodeGooglePolyline(encoded) {
    const coords = [];
    let index = 0;
    let lat = 0;
    let lng = 0;

    while (index < encoded.length) {
        for (const axis of ['lat', 'lng']) {
            let result = 0;
            let shift = 0;
            let byte;
            do {
                byte = encoded.charCodeAt(index++) - 63;
                result |= (byte & 0x1f) << shift;
                shift += 5;
            } while (byte >= 0x20);
            const delta = (result & 1) ? ~(result >> 1) : (result >> 1);
            if (axis === 'lat') lat += delta;
            else lng += delta;
        }
        coords.push({ lat: lat / 1e5, lng: lng / 1e5 });
    }
    return coords;
}

export async function fetchOsrmRoute(originLat, originLng, destinationLat, destinationLng) {
    try {
        // Google Routes es el proveedor principal. La llamada pasa por
        // Laravel para no exponer la clave de servidor y para reutilizar la
        // caché entre cliente/conductor y pequeños movimientos del GPS.
        const { data } = await window.axios.post(route('maps.route'), {
            origin_lat: originLat,
            origin_lng: originLng,
            destination_lat: destinationLat,
            destination_lng: destinationLng,
        });
        return {
            coords: decodeGooglePolyline(data.encoded_polyline),
            distanceKm: data.distance_km,
            durationMin: data.duration_min,
        };
    } catch {
        // Si Google falla por cuota, configuración o red, OSRM mantiene la
        // carrera operativa. Este fallback no se usa mientras Google responda.
    }

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
        const route = data?.routes?.[0];
        const coords = (route?.geometry?.coordinates ?? []).map(([lng, lat]) => ({ lat, lng }));
        const distanceKm = typeof route?.distance === 'number' ? route.distance / 1000 : null;
        // Duración real de manejo que ya trae la misma respuesta de OSRM (antes
        // se descartaba) — más precisa que estimarla con una velocidad
        // promedio fija (ver Utils/eta.js), porque ya tiene en cuenta el tipo
        // de vía real del recorrido.
        const durationMin = typeof route?.duration === 'number' ? route.duration / 60 : null;

        return { coords, distanceKm, durationMin };
    } catch {
        // Si el servicio gratuito de ruteo no responde, no rompemos el flujo —
        // simplemente no se ve la línea del recorrido, y quien llama se queda
        // sin distancia real (cae de vuelta a la línea recta que ya calculaba).
        return { coords: [], distanceKm: null, durationMin: null };
    }
}
