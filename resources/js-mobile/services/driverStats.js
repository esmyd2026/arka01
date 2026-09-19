import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

// Sin filtros todavía en esta primera versión de la pantalla — trae los
// totales generales y los últimos 14 días de ganancia (ver
// App\Services\Driver\DriverStatsFinder::forDriver()), más la primera
// página del historial.
export async function fetchDriverStats() {
    const response = await fetch(`${API_BASE_URL}/api/v1/driver/stats`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudieron cargar tus indicadores.');
    }

    return data;
}
