const STARTUP_SPLASH_KEY = 'arka01_startup_splash_seen';

export function shouldShowStartupSplash(isAuthenticated) {
    if (!isAuthenticated) return false;

    try {
        if (sessionStorage.getItem(STARTUP_SPLASH_KEY) === '1') return false;
        sessionStorage.setItem(STARTUP_SPLASH_KEY, '1');
    } catch {
        // Si el navegador bloquea sessionStorage, evitamos interrumpir el
        // arranque de la aplicación.
    }

    return true;
}

export function resetStartupSplash() {
    try {
        sessionStorage.removeItem(STARTUP_SPLASH_KEY);
    } catch {
        // Cerrar sesión no depende del almacenamiento del navegador.
    }
}
