import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

export async function fetchDriverStatus() {
    const response = await fetch(`${API_BASE_URL}/api/v1/driver/status`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo consultar tu estado.');
    }

    return data.driver;
}

// Lanza un Error con el motivo exacto del bloqueo (DriverProfile::availabilityBlockReason())
// cuando el servidor rechaza conectarse — la pantalla lo muestra tal cual,
// mismo mensaje que ya usa Driver/Profile.vue en la web.
export async function updateDriverLocation(lat, lng, isAvailable) {
    let response;
    try {
        response = await fetch(`${API_BASE_URL}/api/v1/driver/location`, {
            method: 'POST',
            headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
            body: JSON.stringify({ lat, lng, is_available: isAvailable }),
        });
    } catch (e) {
        throw new Error('No se pudo conectar con el servidor.');
    }

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo actualizar tu estado.');
    }

    return data.driver;
}
