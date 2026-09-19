import { API_BASE_URL } from '../apiBase';
import { getToken } from './auth';

// El servidor decide la audiencia según el rol de quien pide (ver
// Api\V1\CouponController::index()) — el frontend no manda ningún filtro.
export async function fetchCoupons() {
    const token = await getToken();
    const response = await fetch(`${API_BASE_URL}/api/v1/coupons`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudieron cargar los cupones.');
    }

    return data.coupons;
}
