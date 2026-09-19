// Los builds de APK (`vite build`) consumen el backend real. El servidor
// local se conserva solo para `npm run dev:mobile`, donde adb reverse permite
// que localhost:8000 dentro del emulador llegue a Laravel en esta PC.
// VITE_MOBILE_API_BASE_URL sigue permitiendo usar staging u otra URL sin
// volver a editar el código antes de compilar.
const configuredBaseUrl = import.meta.env.VITE_MOBILE_API_BASE_URL?.trim();
const defaultBaseUrl = import.meta.env.PROD ? 'https://arka01.com' : 'http://localhost:8000';

export const API_BASE_URL = (configuredBaseUrl || defaultBaseUrl).replace(/\/+$/, '');
