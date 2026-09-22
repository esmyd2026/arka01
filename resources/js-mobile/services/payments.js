import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

function errorMessageFrom(data, fallback) {
    if (data.errors) {
        const firstField = Object.keys(data.errors)[0];
        if (firstField) return data.errors[firstField][0];
    }
    return data.message || fallback;
}

/** El cliente adjunta el comprobante de una transferencia a una cooperativa. */
export async function uploadPaymentProof(rideId, file) {
    const body = new FormData();
    body.append('payment_proof', file);

    let response;
    try {
        response = await fetch(`${API_BASE_URL}/api/v1/rides/${rideId}/payment-proof`, {
            method: 'POST',
            headers: await authHeaders(),
            body,
        });
    } catch (e) {
        throw new Error('No se pudo conectar con el servidor.');
    }

    const data = await response.json();
    if (!response.ok) {
        throw new Error(errorMessageFrom(data, 'No se pudo enviar el comprobante.'));
    }

    return data.ride;
}

/** En efectivo, el conductor confirma que recibió el valor del cliente. */
export async function confirmCashPayment(rideId) {
    const response = await fetch(`${API_BASE_URL}/api/v1/rides/${rideId}/confirm-cash`, {
        method: 'POST',
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(errorMessageFrom(data, 'No se pudo confirmar el efectivo.'));
    }

    return data.ride;
}
