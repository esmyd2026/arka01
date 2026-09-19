import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

export async function browseVanTrips(filters = {}) {
    const params = new URLSearchParams();
    if (filters.origin_city_id) params.set('origin_city_id', filters.origin_city_id);
    if (filters.destination_city_id) params.set('destination_city_id', filters.destination_city_id);
    if (filters.travel_date) params.set('travel_date', filters.travel_date);

    const response = await fetch(`${API_BASE_URL}/api/v1/van-trips/browse?${params.toString()}`, {
        headers: await authHeaders(),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudieron cargar los viajes.');
    }

    return data;
}

export async function reserveVanTripSeats(tripId, seatsReserved) {
    const response = await fetch(`${API_BASE_URL}/api/v1/van-trips/${tripId}/reservations`, {
        method: 'POST',
        headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
        body: JSON.stringify({ seats_reserved: seatsReserved }),
    });

    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError || data.message || 'No se pudo reservar el viaje.');
    }

    return data;
}
