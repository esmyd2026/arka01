import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

// Devuelve una forma distinta según el rol (owner_type: client/driver/
// cooperative) — ver Api\V1\PlanController::mine(). Solo lectura: el
// catálogo de planes y "pedir un cambio de plan" siguen siendo solo de la
// web (comprobante de pago + revisión de un admin, ver roadmap).
export async function fetchMyPlan() {
    const response = await fetch(`${API_BASE_URL}/api/v1/my-plan`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo consultar tu plan.');
    }

    return data;
}
