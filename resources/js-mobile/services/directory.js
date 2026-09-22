import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

// Con lat/lng el backend prioriza cercanía; sin ubicación conserva el orden
// general del directorio (ver App\Services\Driver\DriverDirectoryFinder::browse()).
export async function fetchDirectory(page = 1, location = null) {
    const token = await getToken();
    const query = new URLSearchParams({ page: String(page) });
    if (location?.lat != null && location?.lng != null) {
        query.set('lat', String(location.lat));
        query.set('lng', String(location.lng));
    }
    const response = await fetch(`${API_BASE_URL}/api/v1/directory?${query}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo cargar el directorio.');
    }

    return data;
}
