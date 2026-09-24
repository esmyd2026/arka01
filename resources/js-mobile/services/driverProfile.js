import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

// Trae el perfil (o null si todavía no lo activó) más los catálogos
// estáticos (tipos de vehículo, comodidades) que la web recibe como props
// de Inertia — ver Api\V1\DriverController::profile().
export async function fetchDriverProfile() {
    const response = await fetch(`${API_BASE_URL}/api/v1/driver/profile`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo cargar el perfil de conductor.');
    }

    return data;
}

// FormData: hay hasta cuatro archivos posibles (documento de identidad,
// licencia, antecedentes, foto de perfil) — Api\Http\Controllers\Api\V1\
// DriverController::updateProfile() reusa DriverProfileUpdater::update(),
// que depende de $request->hasFile() igual que el resto de formularios con
// imagen de esta app.
export async function updateDriverProfile(fields, files = {}) {
    const form = new FormData();
    Object.entries(fields).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') return;
        if (Array.isArray(value)) {
            value.forEach((item) => form.append(`${key}[]`, item));
        } else if (typeof value === 'boolean') {
            form.append(key, value ? '1' : '0');
        } else {
            form.append(key, value);
        }
    });
    Object.entries(files).forEach(([key, file]) => {
        if (file) form.append(key, file);
    });

    const token = await getToken();
    const response = await fetch(`${API_BASE_URL}/api/v1/driver/profile`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: form,
    });

    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'No se pudo actualizar el perfil de conductor.');
    }

    return data.profile;
}

// "Zona de trabajo" declarada por el conductor (sectores con nombre, no
// hexágonos/radio — decisión tomada con el usuario). Va en un endpoint
// aparte de updateDriverProfile() porque es un dato pequeño e independiente
// (App\Services\Driver\DriverCoverageSectorsUpdater), mismo criterio que
// bankAccountForm en la web (Driver/Profile.vue).
export async function updateCoverageSectors(sectorIds) {
    const form = new FormData();
    sectorIds.forEach((id) => form.append('sector_ids[]', id));

    const token = await getToken();
    const response = await fetch(`${API_BASE_URL}/api/v1/driver/profile/coverage-sectors`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: form,
    });

    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'No se pudo actualizar la zona de trabajo.');
    }

    return data.profile;
}
