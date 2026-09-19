import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

// Sin geolocalización todavía en esta primera versión — sin lat/lng el
// backend ordena por mejor calificados en vez de por cercanía (ver
// App\Services\Driver\DriverDirectoryFinder::browse()).
export async function fetchDirectory(page = 1) {
    const token = await getToken();
    const response = await fetch(`${API_BASE_URL}/api/v1/directory?page=${page}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo cargar el directorio.');
    }

    return data;
}
