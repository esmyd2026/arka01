import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

export async function fetchCities() {
    const response = await fetch(`${API_BASE_URL}/api/v1/cities`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudieron cargar las ciudades.');
    }

    return data.cities;
}

// FormData, no JSON: la foto de perfil viaja como archivo y
// Api\V1\ProfileController::update() usa $request->hasFile('avatar') (ver
// App\Services\Profile\ProfileUpdater) — mismo motivo que el resto de
// formularios con imagen de esta app.
export async function updateProfile(fields, avatarFile) {
    const form = new FormData();
    Object.entries(fields).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            form.append(key, value);
        }
    });
    if (avatarFile) {
        form.append('avatar', avatarFile);
    }

    const token = await getToken();
    const response = await fetch(`${API_BASE_URL}/api/v1/profile`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: form,
    });

    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'No se pudo actualizar el perfil.');
    }

    return data.user;
}

export async function updatePassword(fields) {
    const response = await fetch(`${API_BASE_URL}/api/v1/profile/password`, {
        method: 'PUT',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify(fields),
    });
    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'No se pudo actualizar la contraseña.');
    }
    return data;
}
