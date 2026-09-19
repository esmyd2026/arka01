import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

export async function fetchSavedRoutes() {
    const response = await fetch(`${API_BASE_URL}/api/v1/saved-routes`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudieron cargar tus rutas.');
    }

    return data.saved_routes;
}

export async function addSavedRoute(fields) {
    const response = await fetch(`${API_BASE_URL}/api/v1/saved-routes`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify(fields),
    });

    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'No se pudo guardar la ruta.');
    }

    return data.saved_route;
}

export async function deleteSavedRoute(id) {
    const response = await fetch(`${API_BASE_URL}/api/v1/saved-routes/${id}`, {
        method: 'DELETE',
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo eliminar la ruta.');
    }

    return data;
}
