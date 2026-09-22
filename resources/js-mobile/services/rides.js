import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

// Extrae el mensaje de error de una respuesta de /api/v1/ride-requests — un
// 422 de validación (App\Services\Ride\RideRequestCreator) trae los
// mensajes en `errors.<campo>`, no en `message` directamente.
function errorMessageFrom(data, fallback) {
    if (data.errors) {
        const firstField = Object.keys(data.errors)[0];
        if (firstField) return data.errors[firstField][0];
    }
    return data.message || fallback;
}

export async function createRideRequest(payload) {
    let response;
    try {
        response = await fetch(`${API_BASE_URL}/api/v1/ride-requests`, {
            method: 'POST',
            headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
    } catch (e) {
        throw new Error('No se pudo conectar con el servidor.');
    }

    const data = await response.json();
    if (!response.ok) {
        throw new Error(errorMessageFrom(data, 'No se pudo pedir la carrera.'));
    }

    return data.ride_request;
}

export async function fetchRideRequest(id) {
    const response = await fetch(`${API_BASE_URL}/api/v1/ride-requests/${id}`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo consultar la solicitud.');
    }

    return data.ride_request;
}

export async function fetchActiveRideRequests() {
    const response = await fetch(`${API_BASE_URL}/api/v1/ride-requests`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudieron cargar las solicitudes.');
    }

    return data.ride_requests;
}

export async function fetchRideHistory() {
    const response = await fetch(`${API_BASE_URL}/api/v1/rides/history`, { headers: await authHeaders() });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'No se pudo cargar el historial.');
    return data.rides ?? [];
}

export async function cancelRideRequest(id) {
    const response = await fetch(`${API_BASE_URL}/api/v1/ride-requests/${id}/cancel`, {
        method: 'POST',
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo cancelar la solicitud.');
    }

    return data;
}

// --- Lado conductor: solicitudes entrantes ---

export async function fetchIncomingRideRequests() {
    const response = await fetch(`${API_BASE_URL}/api/v1/ride-requests/incoming`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudieron cargar las solicitudes entrantes.');
    }

    return data.ride_requests;
}

export async function acceptRideRequest(id) {
    const response = await fetch(`${API_BASE_URL}/api/v1/ride-requests/${id}/accept`, {
        method: 'POST',
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(errorMessageFrom(data, 'No se pudo aceptar la solicitud.'));
    }

    return data.ride;
}

// Cooperativas disponibles para "Elige tu conductor" (pedido explícito del
// usuario: "si el conductor pertenece a una cooperativa"), con la misma
// distancia/tarifa que ya calcula la pantalla web equivalente.
export async function fetchRideRequestCooperatives(originLat, originLng) {
    const query = originLat != null && originLng != null
        ? `?origin_lat=${originLat}&origin_lng=${originLng}`
        : '';

    const response = await fetch(`${API_BASE_URL}/api/v1/ride-requests/cooperatives${query}`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudieron cargar las cooperativas.');
    }

    return data.cooperatives;
}

export async function rejectRideRequest(id) {
    const response = await fetch(`${API_BASE_URL}/api/v1/ride-requests/${id}/reject`, {
        method: 'POST',
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo rechazar la solicitud.');
    }

    return data;
}
