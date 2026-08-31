<script setup>
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
import { loadGooglePlaces } from '@/Utils/googleMaps';

// Input de dirección con sugerencias de Google Places (decisión explícita del
// usuario: Google se usa SOLO acá, para autocompletar — el mapa y el trazado
// de ruta siguen siendo Leaflet + OSRM, gratis, ver Components/FleetMap.vue).
// Sin VITE_GOOGLE_MAPS_API_KEY configurada, se comporta como un campo de
// texto libre normal, sin sugerencias (no rompe nada).
const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    id: { type: String, default: null },
    // Centro para preferir resultados cercanos (ej. la ciudad elegida en el
    // formulario de carrera) — solo una preferencia, no restringe el resto.
    cityBias: { type: Object, default: null },
    // Pedido explícito del usuario ("guardá las que ya ha realizado para que
    // aparezcan como favoritas"): direcciones que este cliente ya usó antes —
    // [{ address, lat, lng, sector_id }]. Aparecen al enfocar el campo VACÍO,
    // antes de escribir nada (mismo criterio que "lugares recientes" de
    // cualquier app de viajes), y no gastan cuota de Google porque no
    // disparan ninguna búsqueda — ya se sabe lat/lng de antes.
    favorites: { type: Array, default: () => [] },
    // Pedido explícito del usuario (bosquejo de referencia): la tarjeta de
    // búsqueda de Inicio (Dashboard.vue) flota sobre el mapa con un fondo
    // blanco cálido, mientras el resto de la app sigue oscura — este
    // componente se reutiliza en las dos, así que en vez de duplicarlo
    // entero solo cambia de paleta con esta prop, sin tocar su lógica.
    light: { type: Boolean, default: false },
    // Rediseño puramente visual del buscador de Inicio (pedido explícito del
    // usuario: "no quiero que parezca un mapa de Google con un formulario
    // debajo"). Variante propia en vez de tocar `light` de arriba porque
    // `light` ya se reutiliza tal cual en Ride/Request.vue (origen, destino,
    // paradas) — cambiarla ahí habría corrido ese rediseño a una pantalla
    // que no lo pidió. `flat` implica el mismo comportamiento de `light`
    // (lupa fija a la izquierda, dropdown claro) con su propia paleta.
    flat: { type: Boolean, default: false },
});

// `focus` es nuevo (pedido explícito del usuario: la card compacta de
// Inicio revela Recientes recién cuando el cliente toca el buscador) — no
// reemplaza ni cambia el manejo interno de foco de este componente (abrir
// favoritos/sugerencias), solo le avisa al padre que puede reaccionar visualmente.
const emit = defineEmits(['update:modelValue', 'place-selected', 'clear', 'selection-loading', 'focus']);

let placesLib = null;
let placesLoading = null;
let sessionToken = null;
let debounceTimer = null;
let suggestionRequest = 0;

// Bug real reportado por el usuario (lista de sugerencias con datos válidos
// en la respuesta de Google, pero "Unhandled error during execution of
// render function" al pintarla): un ref() común envuelve su contenido en un
// Proxy reactivo de Vue — los objetos que devuelve el SDK de Google
// (AutocompleteSuggestion/PlacePrediction) dependen de estado interno
// atado a su identidad real, y ese Proxy se la cambia, rompiendo sus
// getters. shallowRef() reacciona igual (reemplazamos la lista entera, nunca
// mutamos un elemento suelto) pero deja el contenido tal cual, sin envolver.
const suggestions = shallowRef([]);
const open = ref(false);
const loading = ref(false);

// No descargar Google Maps al abrir una pantalla. En móvil es uno de los
// recursos externos más pesados y muchas personas ni siquiera tocarán este
// campo. Se carga una sola vez, recién al enfocarlo o empezar a escribir.
function ensurePlacesLoaded() {
    if (placesLib) return Promise.resolve(placesLib);
    if (!placesLoading) {
        placesLoading = loadGooglePlaces().then((lib) => {
            placesLib = lib;
            return lib;
        });
    }
    return placesLoading;
}

function newSessionToken() {
    if (!placesLib) return null;
    sessionToken = new placesLib.AutocompleteSessionToken();
    return sessionToken;
}

function onInput(event) {
    const value = event.target.value;
    emit('update:modelValue', value);

    clearTimeout(debounceTimer);

    if (!value.trim()) {
        // Campo vaciado de nuevo: volver a ofrecer los favoritos en vez de
        // dejar el dropdown cerrado sin más.
        suggestions.value = [];
        open.value = props.favorites.length > 0;
        return;
    }

    if (value.trim().length < 3) {
        suggestions.value = [];
        open.value = false;
        return;
    }

    debounceTimer = setTimeout(async () => {
        await ensurePlacesLoaded();
        fetchSuggestions(value);
    }, 300);
}

const showFavorites = computed(() => open.value && !props.modelValue?.trim() && props.favorites.length > 0);
const showSuggestions = computed(() => open.value && !showFavorites.value && suggestions.value.length > 0);

function selectFavorite(place) {
    emit('update:modelValue', place.address);
    // sectorId solo viene en favoritos (ya se sabía de cuando se usó antes) —
    // una sugerencia de Google en vivo no tiene forma de saber el sector.
    emit('place-selected', { lat: place.lat, lng: place.lng, address: place.address, sectorId: place.sector_id ?? null });
    open.value = false;
}

async function fetchSuggestions(text) {
    // Bug real reportado: con la key mal configurada del lado de Google
    // Cloud (falta habilitar "Places API (New)" o la facturación), esta
    // clase puede faltar aunque la librería haya cargado — llamarla igual
    // tira un error dentro del propio SDK de Google que no se puede atrapar
    // desde acá. Mejor no intentarlo — mismo comportamiento que sin key.
    if (!placesLib?.AutocompleteSuggestion) return;

    const requestId = ++suggestionRequest;
    loading.value = true;

    try {
        const request = {
            input: text,
            sessionToken: sessionToken ?? newSessionToken(),
            includedRegionCodes: ['ec'],
        };

        // Preferir resultados cerca de la ciudad elegida, sin restringir el
        // resto del país (alguien puede escribir una referencia de otra ciudad).
        if (props.cityBias?.lat != null) {
            request.locationBias = {
                center: { lat: props.cityBias.lat, lng: props.cityBias.lng },
                radius: 30000,
            };
        }

        const { suggestions: results } = await placesLib.AutocompleteSuggestion.fetchAutocompleteSuggestions(request);
        // Una respuesta vieja no debe reemplazar la búsqueda más reciente.
        if (requestId !== suggestionRequest) return;
        suggestions.value = results ?? [];
        open.value = suggestions.value.length > 0;
    } catch (error) {
        // Si la API falla (key inválida, cuota, red), no rompemos el
        // formulario — el campo sigue funcionando como texto libre. Pedido
        // explícito del usuario ("coloca log también"): sin este log, un
        // error acá quedaba completamente invisible (el pedido a Google
        // podía llegar a verse "exitoso" en Network y sin embargo esto
        // fallar después, al armar los objetos de sugerencia).
        console.warn('Arka01: Google Places respondió pero no se pudieron armar las sugerencias.', error);
        if (requestId !== suggestionRequest) return;
        suggestions.value = [];
        open.value = false;
    } finally {
        if (requestId === suggestionRequest) loading.value = false;
    }
}

// Bug real reportado ("el precio se queda pegado en un valor anterior"):
// fetchFields() es asíncrono — si el cliente toca una sugerencia y, antes de
// que resuelva, toca otra (o la misma dos veces seguidas), la que resuelve
// último "gana" sin importar cuál se tocó último de verdad, pudiendo dejar
// lat/lng de una sugerencia vieja. Un token de selección descarta cualquier
// resultado que ya no sea el más reciente.
let selectionToken = 0;

async function selectSuggestion(suggestion) {
    const myToken = ++selectionToken;
    // Cierra ya mismo, no al final: evita poder tocar una segunda sugerencia
    // mientras la primera todavía está resolviendo sus coordenadas.
    open.value = false;
    // La ficha detallada (coordenadas + dirección formal) puede tardar unos
    // segundos aunque la sugerencia ya se haya mostrado. El padre necesita
    // conocer este estado para no dejar la pantalla aparentemente congelada.
    emit('selection-loading', true);

    try {
        const place = suggestion.placePrediction.toPlace();
        await place.fetchFields({ fields: ['location', 'formattedAddress'] });
        if (myToken !== selectionToken) return;

        emit('update:modelValue', place.formattedAddress ?? suggestion.placePrediction.text.text);
        emit('place-selected', {
            lat: place.location.lat(),
            lng: place.location.lng(),
            address: place.formattedAddress ?? suggestion.placePrediction.text.text,
        });
    } catch {
        // Se pudo listar la sugerencia pero no resolver sus coordenadas — se
        // deja el texto nomás, el cliente puede marcar el punto en el mapa.
        if (myToken !== selectionToken) return;
        emit('update:modelValue', suggestion.placePrediction.text.text);
    } finally {
        // Una selección anterior nunca debe apagar el loading de una más
        // reciente que todavía se esté resolviendo.
        if (myToken === selectionToken) emit('selection-loading', false);
    }

    if (myToken !== selectionToken) return;
    suggestions.value = [];
    // Nueva sesión para la próxima búsqueda (así se factura como una sola
    // sesión de autocompletado + selección, no una por cada tecla).
    sessionToken = null;
}

// Botón "X" (pedido explícito del usuario): antes había que borrar el texto
// a mano, y encima eso NO limpiaba lat/lng/sector ya elegidos — quien
// escuchaba este componente (Ride/Request.vue) seguía calculando el precio
// sobre el punto viejo aunque el campo se viera vacío. El evento `clear` le
// avisa al padre que también tiene que soltar esos datos, no solo el texto.
function clearField() {
    emit('update:modelValue', '');
    emit('clear');
    suggestions.value = [];
    open.value = false;
    sessionToken = null;
}

function close() {
    open.value = false;
}

onBeforeUnmount(() => clearTimeout(debounceTimer));
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            type="text"
            :value="modelValue"
            :placeholder="placeholder"
            class="w-full focus:ring-arka-primary"
            :class="
                flat
                    ? 'min-h-[52px] rounded-[15px] ps-11 pe-10 border border-transparent bg-[#F5F7F6] text-arka-base placeholder:text-[#8D9793] transition-colors duration-200 focus:border-arka-primary/40 focus:bg-white'
                    : light
                        ? 'min-h-12 rounded-full ps-11 pe-10 border border-arka-base/[0.06] bg-white text-arka-base placeholder:text-arka-base/40 shadow-[0_8px_24px_rgba(15,23,42,0.06)] focus:border-arka-primary focus:shadow-[0_10px_28px_rgba(52,211,153,0.12)]'
                        : 'rounded-arka pe-9 border-arka-text-muted/20 bg-transparent text-arka-text focus:border-arka-primary'
            "
            autocomplete="off"
            @input="onInput"
            @keydown.escape="close"
            @focus="() => { ensurePlacesLoaded(); open = !modelValue?.trim() ? favorites.length > 0 : suggestions.length > 0; emit('focus'); }"
        />

        <!-- Ícono de lupa: pedido explícito del usuario, con imagen de
             referencia — en la variante clara vive SIEMPRE a la izquierda,
             como cualquier barra de búsqueda (no se esconde al escribir, ese
             es el trabajo del botón de limpiar a la derecha). En la
             variante oscura se mantiene el comportamiento de siempre
             (a la derecha, solo mientras el campo está vacío), sin tocar
             ninguna otra pantalla que ya la usa así. -->
        <span
            v-if="light || flat || !modelValue?.trim()"
            class="pointer-events-none absolute inset-y-0 flex items-center"
            :class="flat ? 'left-0 ps-4 text-[#737D79]' : light ? 'left-0 ps-4 text-arka-base/40' : 'right-0 px-3 text-arka-text-muted'"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="7" stroke-linecap="round" stroke-linejoin="round" />
                <path stroke-linecap="round" stroke-linejoin="round" d="m20 20-3.5-3.5" />
            </svg>
        </span>

        <!-- Limpiar el campo (pedido explícito del usuario): borra el texto Y
             lo que ya se había elegido (lat/lng/sector), no solo lo visible. -->
        <button
            v-if="modelValue?.trim()"
            type="button"
            class="absolute inset-y-0 right-0 flex items-center px-2.5"
            :class="flat ? 'text-[#737D79] hover:text-arka-base/70' : light ? 'text-arka-base/40 hover:text-arka-base/70' : 'text-arka-text-muted hover:text-arka-text'"
            aria-label="Limpiar"
            tabindex="-1"
            @click="clearField"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18" />
            </svg>
        </button>

        <!-- Bug reportado por el usuario: el mapa de Leaflet (Components/FleetMap.vue,
             más abajo en la pantalla) trae sus propios controles internos con
             z-index hasta 1000 (.leaflet-control) — con el z-40/z-50 de acá,
             el mapa terminaba tapando el desplegable. Subido bien por encima
             de eso para que gane siempre, sin importar qué mapa haya debajo. -->
        <div v-if="open" class="fixed inset-0 z-[1400]" @click="close" />

        <!-- Lugares ya usados antes (pedido explícito del usuario) — solo
             antes de escribir nada, como "lugares recientes". -->
        <ul
            v-if="showFavorites"
            class="absolute z-[1500] mt-1 w-full max-h-56 overflow-y-auto rounded-arka border shadow-lg py-1"
            :class="light || flat ? 'border-arka-base/10 bg-white' : 'border-arka-text-muted/20 bg-arka-card'"
        >
            <li v-for="place in favorites" :key="place.address">
                <button
                    type="button"
                    class="w-full px-3 py-2 text-start text-sm flex items-center gap-2"
                    :class="light || flat ? 'text-arka-base hover:bg-arka-cream' : 'text-arka-text hover:bg-arka-base'"
                    @click="selectFavorite(place)"
                >
                    <span class="text-arka-primary-bright shrink-0">★</span>
                    <span class="truncate">{{ place.address }}</span>
                </button>
            </li>
        </ul>

        <ul
            v-else-if="showSuggestions"
            class="absolute z-[1500] mt-1 w-full max-h-56 overflow-y-auto rounded-arka border shadow-lg py-1"
            :class="light || flat ? 'border-arka-base/10 bg-white' : 'border-arka-text-muted/20 bg-arka-card'"
        >
            <li v-for="suggestion in suggestions" :key="suggestion.placePrediction.placeId">
                <button
                    type="button"
                    class="w-full px-3 py-2 text-start text-sm"
                    :class="light || flat ? 'text-arka-base hover:bg-arka-cream' : 'text-arka-text hover:bg-arka-base'"
                    @click="selectSuggestion(suggestion)"
                >
                    {{ suggestion.placePrediction.text.text }}
                </button>
            </li>
        </ul>
    </div>
</template>
