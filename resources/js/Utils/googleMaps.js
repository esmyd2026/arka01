// Carga perezosa y única del SDK de Google Maps. Lo comparten mapas y Places:
// una pantalla que usa ambos nunca descarga dos veces el mismo script.
let loadPromise = null;

export function loadGoogleMaps() {
    const apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;
    if (!apiKey) return Promise.resolve(null);

    if (loadPromise) return loadPromise;

    loadPromise = new Promise((resolve) => {
        if (window.google?.maps) {
            resolve(window.google.maps);
            return;
        }

        window.__arkaGoogleMapsCallback = () => resolve(window.google?.maps ?? null);

        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&libraries=places&loading=async&callback=__arkaGoogleMapsCallback`;
        script.async = true;
        // Si la key es inválida o hay un corte de red, no rompemos el flujo de
        // pedir una carrera — el campo simplemente queda sin sugerencias.
        // Pedido explícito del usuario: dejar rastro en la consola de POR QUÉ
        // no cargó — el caso más común es ERR_BLOCKED_BY_CLIENT, una
        // extensión del navegador (bloqueador de anuncios, Brave Shields)
        // frenando el script antes de que llegue a Google, no un problema de
        // configuración.
        script.onerror = () => {
            console.warn(
                'Arka01: no se pudo cargar el script de Google Maps — si en Network ves "ERR_BLOCKED_BY_CLIENT", es una extensión del navegador (bloqueador de anuncios, Brave Shields) frenándolo, no un problema de la key ni del servidor.'
            );
            resolve(null);
        };
        document.head.appendChild(script);
    });

    return loadPromise;
}

export async function loadGooglePlaces() {
    const maps = await loadGoogleMaps();
    if (!maps) return null;

    try {
        const places = await maps.importLibrary('places');
        if (!places?.AutocompleteSuggestion || !places?.AutocompleteSessionToken) {
            console.warn('Arka01: habilite Places API (New) para usar el autocompletado.');
            return null;
        }
        return places;
    } catch {
        return null;
    }
}
