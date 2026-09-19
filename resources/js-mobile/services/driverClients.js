import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function headers(json = false) {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(json ? { 'Content-Type': 'application/json' } : {}) };
}

async function request(path, options = {}, fallback = 'No se pudo completar la acción.') {
    let response;
    try { response = await fetch(`${API_BASE_URL}/api/v1${path}`, { ...options, headers: { ...(await headers(Boolean(options.body))), ...(options.headers || {}) } }); }
    catch (_) { throw new Error('No se pudo conectar con el servidor.'); }
    const data = await response.json();
    if (!response.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(first || data.message || fallback);
    }
    return data;
}

export const fetchDriverClients = (filter = 'todos', sort = 'recientes') => request(`/driver/invitations?filter=${encodeURIComponent(filter)}&sort=${encodeURIComponent(sort)}`);
export const searchClients = (query) => request(`/driver/clients/search?q=${encodeURIComponent(query)}`, {}, 'No se pudieron buscar clientes.');
export const inviteClient = (clientUserId) => request('/driver/invitations', { method: 'POST', body: JSON.stringify({ client_user_id: clientUserId }) }, 'No se pudo enviar la solicitud.');
export const acceptClientInvitation = (id) => request(`/driver/invitations/${id}/accept`, { method: 'POST' }, 'No se pudo aceptar la invitación.');
export const rejectClientInvitation = (id) => request(`/driver/invitations/${id}/reject`, { method: 'POST' }, 'No se pudo rechazar la invitación.');
export const leaveClientFleet = (id) => request(`/driver/fleets/${id}/leave`, { method: 'POST' }, 'No se pudo salir de la flota.');
export const toggleClientRequests = (id) => request(`/driver/fleets/${id}/toggle-requests`, { method: 'POST' }, 'No se pudo cambiar la recepción de solicitudes.');
