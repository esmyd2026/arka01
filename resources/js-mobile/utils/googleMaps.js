// Copia de resources/js/Utils/googleMaps.js — mismo código exacto, sin
// dependencias de Inertia/Blade (usa import.meta.env, funciona igual en el
// build móvil). Se duplica en vez de importarse desde ../../js/Utils/ para
// que resources/js-mobile/ no dependa de rutas cruzadas hacia la entrada
// web — cualquier cambio real al comportamiento de carga de Google Maps
// debe aplicarse en los dos lados.
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
        script.onerror = () => {
            console.warn('Arka01: no se pudo cargar el script de Google Maps.');
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
