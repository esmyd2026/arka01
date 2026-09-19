import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

export async function fetchSupport() {
    const response = await fetch(`${API_BASE_URL}/api/v1/support`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo cargar el centro de ayuda.');
    }

    return data;
}

export async function sendSupportMessage(body) {
    const response = await fetch(`${API_BASE_URL}/api/v1/support/messages`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify({ body }),
    });

    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'No se pudo enviar el mensaje.');
    }

    return data;
}
