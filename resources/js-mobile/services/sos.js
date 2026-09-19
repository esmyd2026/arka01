import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

// Botón SOS (sección 8): solo funciona durante una carrera en curso — ver
// App\Services\Security\SosAlertSender::trigger(), la misma regla que la
// web. Avisa por correo a los contactos de confianza con correo cargado.
export async function triggerSos(rideId) {
    const token = await getToken();
    const response = await fetch(`${API_BASE_URL}/api/v1/rides/${rideId}/sos`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.errors?.ride?.[0] || data.message || 'No se pudo activar la alerta.');
    }

    return data.notified_contacts_count;
}
