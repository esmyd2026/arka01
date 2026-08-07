// Carga perezosa y única del script de Google Maps JS (sección 9.3 y decisión
// explícita del usuario: Google se usa SOLO para el autocompletado de
// direcciones — el mapa visual y el trazado de ruta siguen siendo Leaflet +
// OpenStreetMap + OSRM, gratis, sin tocar). Si no hay API key configurada,
// `loadGooglePlaces()` resuelve a `null` en vez de tirar error, para que los
// campos de dirección sigan funcionando como texto libre normal.
let loadPromise = null;

export function loadGooglePlaces() {
    const apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;
    if (!apiKey) return Promise.resolve(null);

    if (loadPromise) return loadPromise;

    loadPromise = new Promise((resolve) => {
        if (window.google?.maps?.places) {
            resolve(window.google.maps.places);
            return;
        }

        window.__arkaGoogleMapsCallback = async () => {
            try {
                const places = await window.google.maps.importLibrary('places');

                // Bug real reportado: si el proyecto de Google Cloud no tiene
                // habilitada "Places API (New)" (o falta la cuenta de
                // facturación), la librería igual carga pero sin estas dos
                // clases — usarla igual termina en un error sin atrapar
                // dentro del propio SDK de Google. Mejor tratarlo como "no
                // disponible" acá mismo, igual que sin API key.
                if (!places?.AutocompleteSuggestion || !places?.AutocompleteSessionToken) {
                    // Pedido explícito del usuario ("coloca log también"): sin
                    // esto, hay que abrir la pestaña Network a mano para
                    // enterarse de que a Google le faltó habilitar "Places
                    // API (New)" o la facturación del proyecto.
                    console.warn(
                        'Arka01: Google Places cargó pero sin autocompletado — revisá que "Places API (New)" esté habilitada y que el proyecto tenga facturación activa en Google Cloud Console.'
                    );
                    resolve(null);
                    return;
                }

                resolve(places);
            } catch {
                resolve(null);
            }
        };

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
