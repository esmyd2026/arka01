import { distanceKm } from './haversine';

// Velocidad promedio urbana asumida para estimar cuánto falta (no hay datos
// de tráfico en tiempo real, y no se usa Google Maps para esto — sección 9.3:
// se mantiene el trazado gratuito). Es una aproximación para que el cliente
// tenga una idea, no una promesa exacta tipo "llega en 4 min".
const AVERAGE_URBAN_SPEED_KMH = 22;

export function etaMinutes(distanceKmValue) {
    if (distanceKmValue == null) return null;
    return Math.max(1, Math.round((distanceKmValue / AVERAGE_URBAN_SPEED_KMH) * 60));
}

/**
 * Distancia y tiempo estimado entre dos puntos (ej. el conductor en vivo y
 * el punto de recogida del cliente). Devuelve null si falta algún dato.
 */
export function etaBetween(lat1, lng1, lat2, lng2) {
    if (lat1 == null || lng1 == null || lat2 == null || lng2 == null) return null;

    const km = distanceKm(lat1, lng1, lat2, lng2);
    return { km, minutes: etaMinutes(km) };
}
