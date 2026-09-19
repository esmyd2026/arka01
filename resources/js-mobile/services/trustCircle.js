import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

export async function fetchTrustCircle() {
    const response = await fetch(`${API_BASE_URL}/api/v1/trust-circle`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo cargar tu círculo de confianza.');
    }

    return data;
}

export async function searchTrustCircle(term) {
    const response = await fetch(`${API_BASE_URL}/api/v1/trust-circle/search?q=${encodeURIComponent(term)}`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo buscar.');
    }

    return data.people;
}

export async function sendTrustCircleRequest(userPublicId, relationshipLabel) {
    const response = await fetch(`${API_BASE_URL}/api/v1/trust-circle`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_public_id: userPublicId, relationship_label: relationshipLabel || null }),
    });

    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'No se pudo enviar la solicitud.');
    }

    return data;
}

export async function respondTrustCircleRequest(connectionPublicId, action) {
    const response = await fetch(`${API_BASE_URL}/api/v1/trust-circle/${connectionPublicId}/respond`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify({ action }),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo responder la solicitud.');
    }

    return data;
}

export async function updateTrustCircleSettings(connectionPublicId, settings) {
    const response = await fetch(`${API_BASE_URL}/api/v1/trust-circle/${connectionPublicId}/settings`, {
        method: 'PUT',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify(settings),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo actualizar la privacidad.');
    }

    return data;
}

export async function removeTrustCircleConnection(connectionPublicId) {
    const response = await fetch(`${API_BASE_URL}/api/v1/trust-circle/${connectionPublicId}`, {
        method: 'DELETE',
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo eliminar la conexión.');
    }

    return data;
}

export async function inviteRecommendedDriver(driverPublicId) {
    const response = await fetch(`${API_BASE_URL}/api/v1/trust-circle/drivers/invite`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify({ driver_public_id: driverPublicId }),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo enviar la invitación.');
    }

    return data;
}
