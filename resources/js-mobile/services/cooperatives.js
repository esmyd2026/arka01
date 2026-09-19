import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

export async function fetchCooperatives(search = '') {
    const query = search ? `?q=${encodeURIComponent(search)}` : '';
    const response = await fetch(`${API_BASE_URL}/api/v1/cooperatives${query}`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo cargar el directorio de cooperativas.');
    }

    return data.cooperatives;
}

export async function attachCooperative(cooperativeId) {
    const response = await fetch(`${API_BASE_URL}/api/v1/cooperatives/${cooperativeId}/attach`, {
        method: 'POST',
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.errors?.cooperative?.[0] || data.message || 'No se pudo agregar la cooperativa.');
    }

    return data;
}

export async function detachCooperative(cooperativeId) {
    const response = await fetch(`${API_BASE_URL}/api/v1/cooperatives/${cooperativeId}/detach`, {
        method: 'DELETE',
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo retirar la cooperativa.');
    }

    return data;
}

export async function fetchCooperativeInvitations() {
    const response = await fetch(`${API_BASE_URL}/api/v1/cooperative-driver-invitations`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudieron cargar las invitaciones.');
    }

    return data.memberships;
}

export async function respondCooperativeInvitation(membershipId, decision) {
    const response = await fetch(`${API_BASE_URL}/api/v1/cooperative-driver-invitations/${membershipId}/respond`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify({ decision }),
    });

    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'No se pudo responder la invitación.');
    }

    return data;
}
