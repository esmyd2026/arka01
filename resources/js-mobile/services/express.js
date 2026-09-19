import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

async function handle(response) {
    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'Ocurrió un error con Expresos.');
    }
    return data;
}

export async function fetchMyExpressRoutes() {
    const response = await fetch(`${API_BASE_URL}/api/v1/express-routes/mine`, { headers: await authHeaders() });
    return handle(response);
}

export async function fetchAvailableExpressRoutes() {
    const response = await fetch(`${API_BASE_URL}/api/v1/express-routes/available`, { headers: await authHeaders() });
    return handle(response);
}

export async function publishExpressRoute(fields) {
    const response = await fetch(`${API_BASE_URL}/api/v1/express-routes`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify(fields),
    });
    return handle(response);
}

async function post(path) {
    const response = await fetch(`${API_BASE_URL}/api/v1${path}`, { method: 'POST', headers: await authHeaders() });
    return handle(response);
}

export const pauseExpressRoute = (id) => post(`/express-routes/${id}/pause`);
export const resumeExpressRoute = (id) => post(`/express-routes/${id}/resume`);
export const cancelExpressRoute = (id) => post(`/express-routes/${id}/cancel`);

export async function applyToExpressRoute(routeId, proposedPrice) {
    const response = await fetch(`${API_BASE_URL}/api/v1/express-routes/${routeId}/applications`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify({ proposed_price: proposedPrice || null }),
    });
    return handle(response);
}

export const acceptExpressApplication = (id) => post(`/express-applications/${id}/accept`);
export const rejectExpressApplication = (id) => post(`/express-applications/${id}/reject`);
export const withdrawExpressApplication = (id) => post(`/express-applications/${id}/withdraw`);

export async function fetchExpressRoute(id) {
    const response = await fetch(`${API_BASE_URL}/api/v1/express-routes/${id}`, { headers: await authHeaders() });
    return handle(response);
}
