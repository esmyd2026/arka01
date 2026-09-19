import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

export async function fetchTrustedContacts() {
    const response = await fetch(`${API_BASE_URL}/api/v1/trusted-contacts`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo cargar tus contactos.');
    }

    return data.contacts;
}

// Al menos teléfono o correo es obligatorio (ver
// Api\V1\TrustedContactController::store()) — sin eso el botón SOS no
// tendría forma de avisarle.
export async function addTrustedContact({ name, phone, email, relationship_label }) {
    const response = await fetch(`${API_BASE_URL}/api/v1/trusted-contacts`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, phone: phone || null, email: email || null, relationship_label: relationship_label || null }),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.errors?.phone?.[0] || data.errors?.name?.[0] || data.message || 'No se pudo agregar el contacto.');
    }

    return data.contact;
}

export async function deleteTrustedContact(contactId) {
    const response = await fetch(`${API_BASE_URL}/api/v1/trusted-contacts/${contactId}`, {
        method: 'DELETE',
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo eliminar el contacto.');
    }

    return data;
}
