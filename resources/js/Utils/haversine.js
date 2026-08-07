// Misma fórmula que app/Services/Haversine.php, pero del lado del cliente:
// sirve para mostrar "a X km" al elegir conductor sin esperar una respuesta
// del servidor. El cálculo definitivo que se guarda siempre lo hace el backend.
const EARTH_RADIUS_KM = 6371;

export function distanceKm(lat1, lng1, lat2, lng2) {
    const toRad = (deg) => (deg * Math.PI) / 180;

    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);

    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;

    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

    return EARTH_RADIUS_KM * c;
}
