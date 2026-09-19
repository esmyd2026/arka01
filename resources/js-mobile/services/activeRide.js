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

function actionError(data, fallback) {
    const rawMessage = errorMessageFrom(data, fallback);
    const notificationFailure = /pusher|cURL error|localhost:6001|broadcast/i.test(rawMessage);
    const error = new Error(notificationFailure
        ? 'El cambio pudo guardarse, pero la actualización en vivo no está disponible en este momento.'
        : rawMessage);
    error.stateMayHaveChanged = notificationFailure;
    return error;
}

async function postAction(path, body) {
    let response;
    try {
        response = await fetch(`${API_BASE_URL}/api/v1/rides/${path}`, {
            method: 'POST',
            headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
            body: JSON.stringify(body ?? {}),
        });
    } catch (e) {
        throw new Error('No se pudo conectar con el servidor.');
    }

    const data = await response.json();
    if (!response.ok) {
        throw actionError(data, 'No se pudo actualizar la carrera.');
    }

    return data;
}

export async function fetchActiveRide() {
    const response = await fetch(`${API_BASE_URL}/api/v1/rides/active`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo consultar tu carrera activa.');
    }

    return data.ride;
}

export async function fetchRide(id) {
    const response = await fetch(`${API_BASE_URL}/api/v1/rides/${id}`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo consultar la carrera.');
    }

    return data.ride;
}

export async function startRide(id) {
    return (await postAction(`${id}/start`)).ride;
}

export async function markHeadingToPassenger(id) {
    return (await postAction(`${id}/heading-to-passenger`)).ride;
}

export async function markArrived(id, lat, lng) {
    return (await postAction(`${id}/arrived`, { lat, lng })).ride;
}

export async function markPickedUp(id, lat, lng) {
    return (await postAction(`${id}/picked-up`, { lat, lng })).ride;
}

export async function completeRide(id, lat, lng, completionReason, completionNote) {
    return (
        await postAction(`${id}/complete`, {
            lat,
            lng,
            completion_reason: completionReason,
            completion_note: completionNote,
        })
    ).ride;
}

export async function cancelRide(id, reason, note) {
    return (await postAction(`${id}/cancel`, { reason, note })).ride;
}

export async function updateRideLocation(id, lat, lng) {
    return postAction(`${id}/location`, { lat, lng });
}

export async function reviewRide(id, rating, ratingReasonId, comment) {
    return postAction(`${id}/review`, {
        rating,
        rating_reason_id: ratingReasonId || null,
        comment: comment || null,
    });
}
