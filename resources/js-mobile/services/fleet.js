import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

// Devuelve el JSON de /api/v1/fleet o lanza un Error con el mensaje del
// servidor (o uno genérico si fue un problema de red).
export async function fetchFleet() {
    const token = await getToken();
    let response;
    try {
        response = await fetch(`${API_BASE_URL}/api/v1/fleet`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        });
    } catch (e) {
        throw new Error('No se pudo conectar con el servidor.');
    }

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo cargar la flota.');
    }

    return data;
}

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

// Busca conductores por código de socio, código de invitación, nombre,
// apellido o usuario (nunca por teléfono) para invitarlos a esta flota.
export async function searchDrivers(fleetId, query) {
    let response;
    try {
        response = await fetch(
            `${API_BASE_URL}/api/v1/fleet/${fleetId}/search-drivers?q=${encodeURIComponent(query)}`,
            { headers: await authHeaders() },
        );
    } catch (e) {
        throw new Error('No se pudo conectar con el servidor.');
    }

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo buscar conductores.');
    }

    return data.drivers;
}

export async function inviteDriver(fleetId, driverUserId) {
    let response;
    try {
        response = await fetch(`${API_BASE_URL}/api/v1/fleet/${fleetId}/invitations`, {
            method: 'POST',
            headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
            body: JSON.stringify({ driver_user_id: driverUserId }),
        });
    } catch (e) {
        throw new Error('No se pudo conectar con el servidor.');
    }

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo enviar la invitación.');
    }

    return data;
}

export async function removeMember(memberId) {
    let response;
    try {
        response = await fetch(`${API_BASE_URL}/api/v1/fleet/members/${memberId}`, {
            method: 'DELETE',
            headers: await authHeaders(),
        });
    } catch (e) {
        throw new Error('No se pudo conectar con el servidor.');
    }

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo quitar al conductor.');
    }

    return data;
}

export async function createFleet(name) {
    let response;
    try {
        response = await fetch(`${API_BASE_URL}/api/v1/fleet`, {
            method: 'POST',
            headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
            body: JSON.stringify({ name }),
        });
    } catch (_) { throw new Error('No se pudo conectar con el servidor.'); }
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'No se pudo crear la flota.');
    return data.fleet;
}

export async function cancelInvitation(invitationId) {
    let response;
    try {
        response = await fetch(`${API_BASE_URL}/api/v1/fleet/invitations/${invitationId}`, {
            method: 'DELETE', headers: await authHeaders(),
        });
    } catch (_) { throw new Error('No se pudo conectar con el servidor.'); }
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'No se pudo cancelar la invitación.');
    return data;
}
