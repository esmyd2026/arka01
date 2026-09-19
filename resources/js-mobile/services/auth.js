import { Preferences } from '@capacitor/preferences';
import { SecureStorage } from '@aparajita/capacitor-secure-storage';
import { API_BASE_URL } from '../apiBase';

const TOKEN_KEY = 'arka01_token';
const USER_KEY = 'arka01_user';
const DEVICE_ID_KEY = 'arka01_device_id';

// El token es lo único sensible acá (roadmap Hito 4: "guardar tokens solo
// en almacenamiento seguro del dispositivo") — va en SecureStorage
// (Keychain en iOS / Keystore en Android, cifrado). El device_id y los
// datos de usuario cacheados no son secretos, así que se quedan en
// @capacitor/preferences (sin cifrar, pero no hace falta que lo esté).

// Limpieza única de una fuga real encontrada probando en el emulador: antes
// de migrar a SecureStorage, el token vivía en @capacitor/preferences (sin
// cifrar) bajo esta misma key. La migración nunca borró esa copia vieja —
// `adb install -r` conserva los datos de la app entre instalaciones, así
// que quedaba un token válido en texto plano en el dispositivo aunque el
// código ya no lo leyera de ahí. Se llama una vez al arrancar (ver
// main.js) para que cualquier instalación que venga de antes de este
// cambio quede limpia.
export async function purgeLegacyPlaintextToken() {
    await Preferences.remove({ key: TOKEN_KEY });
}

export async function getDeviceId() {
    const existing = await Preferences.get({ key: DEVICE_ID_KEY });
    if (existing.value) return existing.value;

    const id = crypto.randomUUID();
    await Preferences.set({ key: DEVICE_ID_KEY, value: id });
    return id;
}

export async function exchangeGoogleCode(code) {
    const response = await fetch(`${API_BASE_URL}/api/v1/auth/google/exchange`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ code }),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.errors?.code?.[0] || data.message || 'No se pudo ingresar con Google.');
    return saveSession(data);
}

async function saveSession(data) {
    await SecureStorage.set(TOKEN_KEY, data.token);
    await Preferences.set({ key: USER_KEY, value: JSON.stringify(data.user) });
    return data.user;
}

async function clearSession() {
    await SecureStorage.remove(TOKEN_KEY);
    await Preferences.remove({ key: USER_KEY });
}

export async function login(loginField, password) {
    const deviceId = await getDeviceId();
    const response = await fetch(`${API_BASE_URL}/api/v1/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
            login: loginField,
            password,
            device_id: deviceId,
            platform: 'android',
        }),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo iniciar sesión.');
    }

    return saveSession(data);
}

// `fields` trae account_type, first_name, last_name, email, country_code,
// phone_local, password, password_confirmation y, opcional, ref — la misma
// forma que espera Api\V1\AuthController::register() (que a su vez reusa
// App\Actions\Auth\RegisterUser, igual que el registro web).
export async function register(fields) {
    const deviceId = await getDeviceId();
    const response = await fetch(`${API_BASE_URL}/api/v1/auth/register`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ ...fields, device_id: deviceId, platform: 'android' }),
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || 'No se pudo crear la cuenta.');
    }

    return saveSession(data);
}

export async function logout() {
    const token = await getToken();
    if (token) {
        // El logout del servidor revoca el token y desconecta al conductor
        // de su flota si estaba disponible (ver DriverOfflineOnLogout) — si
        // falla la red, igual se limpia la sesión local abajo.
        await fetch(`${API_BASE_URL}/api/v1/auth/logout`, {
            method: 'POST',
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        }).catch(() => {});
    }

    await clearSession();
}

// Devuelve null si la cuenta se borró bien; en caso de error devuelve el
// mensaje (ej. contraseña incorrecta, o de red) sin tocar la sesión local,
// para que la pantalla lo muestre y la persona pueda reintentar.
export async function deleteAccount(password) {
    try {
        const token = await getToken();
        const response = await fetch(`${API_BASE_URL}/api/v1/account`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
            body: JSON.stringify({ password }),
        });

        const data = await response.json();
        if (!response.ok) {
            return data.message || 'No se pudo eliminar la cuenta.';
        }

        await clearSession();
        return null;
    } catch (e) {
        return 'No se pudo conectar con el servidor. Probá de nuevo.';
    }
}

export async function getToken() {
    const value = await SecureStorage.get(TOKEN_KEY);
    return value ?? null;
}

export async function getStoredUser() {
    const { value } = await Preferences.get({ key: USER_KEY });
    return value ? JSON.parse(value) : null;
}

// Revalida contra la API — el token guardado puede haber sido revocado
// desde otro lado (ej. sesión única cerrándolo al entrar por otro
// dispositivo). Si ya no sirve, limpia la sesión local de una vez.
export async function fetchCurrentUser() {
    const token = await getToken();
    if (!token) return null;

    const response = await fetch(`${API_BASE_URL}/api/v1/auth/me`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });

    if (!response.ok) {
        await clearSession();
        return null;
    }

    const user = await response.json();
    await Preferences.set({ key: USER_KEY, value: JSON.stringify(user) });
    return user;
}
