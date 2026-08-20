/**
 * Manejo seguro de ubicación del usuario mediante sessionStorage
 * 
 * SEGURIDAD: La ubicación se guarda en sessionStorage (no en URL):
 * - ✅ NO aparece en historial del navegador
 * - ✅ NO aparece en server logs
 * - ✅ NO se expone en links compartibles
 * - ✅ Se borra automáticamente al cerrar la pestaña
 * 
 * El sessionStorage es solo para la pestaña actual, no se sincroniza entre pestañas
 */

const STORAGE_KEY = 'arka01_client_location';
const STORAGE_TTL_KEY = 'arka01_location_timestamp';
const LOCATION_MAX_AGE_MS = 5 * 60 * 1000; // 5 minutos

/**
 * Guardar ubicación del cliente en sessionStorage
 * @param {Object} location - { lat, lng, address? }
 */
export function saveClientLocation(location) {
    if (!location || !location.lat || !location.lng) {
        console.warn('Invalid location data', location);
        return;
    }

    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
            lat: location.lat,
            lng: location.lng,
            address: location.address || '',
            timestamp: Date.now(),
        }));
        sessionStorage.setItem(STORAGE_TTL_KEY, Date.now().toString());
    } catch (e) {
        console.error('Could not save location to sessionStorage:', e);
    }
}

/**
 * Recuperar ubicación guardada del cliente
 * @returns {Object|null} { lat, lng, address, timestamp } o null si no existe
 */
export function getClientLocation() {
    try {
        const stored = sessionStorage.getItem(STORAGE_KEY);
        if (!stored) return null;

        const location = JSON.parse(stored);
        
        // Verificar que no esté expirada (5 minutos)
        const age = Date.now() - location.timestamp;
        if (age > LOCATION_MAX_AGE_MS) {
            clearClientLocation();
            return null;
        }

        return location;
    } catch (e) {
        console.error('Could not retrieve location from sessionStorage:', e);
        return null;
    }
}

/**
 * Verificar si hay ubicación guardada válida
 * @returns {boolean}
 */
export function hasClientLocation() {
    return getClientLocation() !== null;
}

/**
 * Limpiar ubicación guardada
 */
export function clearClientLocation() {
    try {
        sessionStorage.removeItem(STORAGE_KEY);
        sessionStorage.removeItem(STORAGE_TTL_KEY);
    } catch (e) {
        console.error('Could not clear location from sessionStorage:', e);
    }
}

/**
 * Obtener la ubicación del cliente para usar en componentes
 * Retorna ubicación guardada o fallback a null
 * @returns {Object|null}
 */
export function useClientLocation() {
    return getClientLocation();
}
