<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import L from 'leaflet';
import { fixLeafletIcons } from '@/Utils/leafletIcons';

// Respaldo reutilizable con Leaflet + OpenStreetMap (sección 9.3: sin costo por uso,
// a diferencia de Google Maps). Se usa tanto para ver la flota en el mapa como
// para elegir origen/destino de una carrera y para el seguimiento en vivo.
const props = defineProps({
    // [{ id, lat, lng, label, color }]
    markers: {
        type: Array,
        default: () => [],
    },
    center: {
        type: Object,
        default: () => ({ lat: -0.1807, lng: -78.4678 }), // Quito, por defecto
    },
    zoom: {
        type: Number,
        default: 13,
    },
    height: {
        type: String,
        default: '320px',
    },
    // Si es true, un clic en el mapa emite "map-click" con { lat, lng } —
    // se usa para elegir el destino de una carrera.
    clickable: {
        type: Boolean,
        default: false,
    },
    // [{ lat, lng }, ...] — recorrido real entre origen y destino (OSRM,
    // gratis, sección 9.3), no solo la línea recta entre los dos puntos.
    route: {
        type: Array,
        default: () => [],
    },
    // Encuadra los marcadores cuando cambia DE QUÉ se compone la lista (fix
    // real reportado: se marcaba el origen y el mapa se centraba bien, pero
    // al marcar el destino ya no se movía ni se veían los dos puntos juntos —
    // antes se dejaba de ajustar apenas se había encuadrado UNA vez, sin
    // importar que después apareciera un segundo punto). No se reajusta si lo
    // único que cambió fue la posición de los mismos marcadores de siempre
    // (ej. el ping en vivo de un conductor moviéndose) — si no, pelearía
    // contra un zoom/pan manual que ya hizo quien mira el mapa. Se puede
    // desactivar del todo en pantallas que manejan su propio centrado con
    // lógica más específica (ej. Ride/Request.vue sigue la geolocalización
    // del cliente vía la prop `center`).
    autoFit: {
        type: Boolean,
        default: true,
    },
    // Permite mostrar muchos marcadores sin usarlos todos para el encuadre.
    // En la solicitud de carrera se ven unidades cercanas, pero el zoom debe
    // concentrarse únicamente en el origen y el destino elegidos.
    fitMarkerIds: {
        type: Array,
        default: () => [],
    },
    // Espacio que debe quedar libre al encuadrar marcadores. Las pantallas
    // de seguimiento tienen tarjetas flotantes arriba y abajo; sin este
    // margen Leaflet centra correctamente, pero deja los puntos importantes
    // detrás de esas tarjetas.
    fitPaddingTop: {
        type: Number,
        default: 40,
    },
    fitPaddingBottom: {
        type: Number,
        default: 40,
    },
    // El mapa de Inicio en móvil llega de borde a borde hasta arriba de la
    // pantalla (pedido explícito del usuario) — con las esquinas de siempre
    // redondeadas, las de arriba quedarían cortando contra el borde real de
    // la pantalla, con un triángulo del fondo oscuro asomando detrás. `false`
    // solo ahí; todas las demás pantallas (donde el mapa SÍ vive dentro de
    // una tarjeta) siguen con esquinas redondeadas por defecto.
    rounded: {
        type: Boolean,
        default: true,
    },
    // Pedido explícito del usuario, con capturas reales comparadas: el mapa
    // oscuro "sobrio" combina bien con el seguimiento en vivo de una carrera
    // (fondo ya oscuro alrededor), pero chocaba con la tarjeta blanca cálida
    // de Inicio ("esta feo no combina") — ahí se prefirió volver al mapa
    // claro de siempre. `true` por defecto para no tocar ninguna pantalla
    // que ya usa este componente; Dashboard.vue es la única que pasa `false`.
    dark: {
        type: Boolean,
        default: true,
    },
    // Documento formal de ajuste UX (sección 18: "verificar controles de
    // Leaflet... solapamiento con navbar"): con una nav flotando ENCIMA del
    // mapa (Dashboard.vue, `transparent-nav`), los controles +/- de Leaflet
    // nacen en la esquina superior izquierda DEL MAPA, justo donde también
    // vive la nav — se corren hacia abajo lo que haga falta para no quedar
    // tapados. '0' en el resto de las pantallas (mapa siempre debajo de una
    // barra sólida de verdad, sin solaparse con nada).
    controlsTopOffset: {
        type: String,
        default: '0px',
    },
    // Bug real reportado por el usuario ("la ubicación actual aparece más
    // abajo, quiero verla en el centro de la pantalla del mapa"): en Inicio
    // (móvil) el bottom sheet tapa visualmente la mitad de abajo del mapa —
    // `setView()`/`center` siempre centran sobre el contenedor COMPLETO
    // (incluida esa mitad tapada), así que el punto pedido queda pegado al
    // borde inferior de la franja que de verdad se alcanza a ver, no en su
    // centro. En píxeles, cuántos hay tapados abajo — se corre el centro
    // "real" hacia abajo la mitad de eso, así el punto pedido sube visualmente
    // al medio de lo visible. `0` en el resto de las pantallas (ninguna otra
    // tapa la mitad del mapa con algo fijo encima).
    centerOffsetY: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['map-click']);

const mapEl = ref(null);
let map = null;
let markerLayer = null;
let routeLine = null;
// Bug real reportado (con captura: "dice que ya viene en camino y no veo lo
// que está sucediendo"): el mapa arrancaba siempre centrado en Quito por
// defecto si la pantalla no pasaba un :center explícito (ej.
// Ride/Show.vue) — nunca se ajustaba solo a dónde están de verdad los
// marcadores. Se ajusta cada vez que cambia la composición de la lista
// (cuántos marcadores hay y de qué tipo), no en cada actualización de
// posición — si no, cada ping de ubicación en vivo del conductor pelearía
// contra un zoom/pan manual que ya hizo quien mira el mapa.
let lastFitSignature = null;

// Centra el mapa en (lat, lng), corriendo el centro "real" hacia abajo
// `centerOffsetY / 2` píxeles cuando corresponde (ver la prop arriba) — así
// el punto pedido aparece en el medio de la franja VISIBLE del mapa, no en
// el centro geométrico de todo el contenedor. `project()`/`unproject()` son
// del propio Leaflet: convierten entre coordenadas geográficas y píxeles
// absolutos a un zoom dado, sin depender de la posición actual del mapa.
function applyView(lat, lng, zoom = props.zoom) {
    if (!map) return;

    if (!props.centerOffsetY) {
        map.setView([lat, lng], zoom);
        return;
    }

    const shiftedPoint = map.project([lat, lng], zoom).add([0, props.centerOffsetY / 2]);
    map.setView(map.unproject(shiftedPoint, zoom), zoom);
}

onMounted(() => {
    fixLeafletIcons();

    map = L.map(mapEl.value);
    applyView(props.center.lat, props.center.lng, props.zoom);

    // Mapa "sobrio" en las dos variantes (pedido explícito del usuario, con
    // capturas comparadas: el OSM claro por defecto se veía "sobrecargado" —
    // parques verdes, vías naranjas, agua celeste, mucho texto — al lado de
    // la referencia, mucho más apagada). Las dos usan CARTO (gratis, sin API
    // key, mismo proveedor que ya se había elegido para el oscuro): "Dark
    // Matter" para seguimiento en vivo, "Positron" —su par clara, en gris
    // suave— para Inicio vía la prop `dark` (ver arriba). Ninguna es el OSM
    // "de colores" de antes.
    const tileUrl = props.dark
        ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
        : 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';

    L.tileLayer(tileUrl, {
        attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 20,
    }).addTo(map);

    markerLayer = L.layerGroup().addTo(map);
    drawMarkers();
    drawRoute();

    if (props.clickable) {
        map.on('click', (e) => emit('map-click', { lat: e.latlng.lat, lng: e.latlng.lng }));
    }

    // Bug real reportado por el usuario (con captura: el mapa se veía
    // "zoomeado" mal, mostrando un río en vez de calles, aunque los
    // controles +/- quedaban bien ubicados): si el contenedor todavía no
    // tenía su tamaño final justo al montar (ej. una altura que depende del
    // viewport, `50vh`), Leaflet mide el lienzo con el tamaño viejo y
    // arrastra ese error hasta que algo fuerza un resize — se ve "de
    // colores" pero mal encuadrado. `invalidateSize()` en el siguiente
    // frame, ya con el layout asentado, corrige la medida sola.
    requestAnimationFrame(() => map?.invalidateSize());

    applyControlsTopOffset();
});

// Corre los controles +/- de Leaflet (esquina superior izquierda DEL MAPA)
// hacia abajo cuando la nav flota encima (ver `controlsTopOffset` arriba) —
// Leaflet no tiene una prop para esto, así que se ajusta el margen del
// contenedor de controles directamente.
function applyControlsTopOffset() {
    const topLeftControls = mapEl.value?.querySelector('.leaflet-top.leaflet-left');
    if (topLeftControls) topLeftControls.style.marginTop = props.controlsTopOffset;
}

watch(() => props.controlsTopOffset, applyControlsTopOffset);

onBeforeUnmount(() => {
    map?.remove();
});

// Pines distintos por tipo de punto (consideración agregada al alcance: "que
// los iconos cambien, origen y destino" — antes todos los marcadores usaban
// el mismo pin genérico de Leaflet y no se distinguía nada de un vistazo).
// SVG inline, sin depender de otro asset de imagen: un pin verde para el
// origen, uno rojo para el destino, y un punto celeste para el conductor en
// vivo (no es un punto fijo, así que un círculo se lee mejor que un pin).
const PIN_SVG = (fill) => `
    <svg width="26" height="36" viewBox="0 0 26 36" xmlns="http://www.w3.org/2000/svg">
        <path d="M13 0C5.8 0 0 5.8 0 13c0 9.75 13 23 13 23s13-13.25 13-23C26 5.8 20.2 0 13 0z" fill="${fill}" stroke="#0b0f0d" stroke-width="1"/>
        <circle cx="13" cy="13" r="5" fill="#0b0f0d" fill-opacity="0.35"/>
    </svg>
`;

const DRIVER_DOT_SVG = `
    <svg width="22" height="22" viewBox="0 0 22 22" xmlns="http://www.w3.org/2000/svg">
        <circle cx="11" cy="11" r="9" fill="#38bdf8" stroke="#0b0f0d" stroke-width="2"/>
        <circle cx="11" cy="11" r="3" fill="#0b0f0d"/>
    </svg>
`;

// Auto visto desde arriba (pedido explícito del usuario: "iconos de carrito
// como los de Uber", en vez del pin celeste genérico de Leaflet que traían
// los conductores de una lista — ej. candidatos al pedir carrera). Pedido
// explícito del usuario, con mockup de referencia ("busca ese carro así
// también"): silueta de auto de verdad (parabrisas adelante, luneta atrás,
// ruedas a los costados) en vez del badge circular de antes — mirando para
// arriba (0°) por defecto, para poder girarlo con CSS cuando se conoce el
// rumbo real (ver carIcon() más abajo). El color es parametrizable (pedido
// explícito del usuario: "unos de mi flota, otros de cooperativa... colocar
// que sea amarillo, los públicos [de otro color]") — verde de la marca por
// defecto.
const CAR_SVG = (fill = '#34d399') => `
    <svg width="26" height="26" viewBox="0 0 26 26" xmlns="http://www.w3.org/2000/svg">
        <rect x="7" y="2.5" width="12" height="21" rx="4.5" fill="${fill}" stroke="#0b0f0d" stroke-width="1"/>
        <rect x="8.8" y="5.5" width="8.4" height="4.5" rx="1.3" fill="#0b0f0d" fill-opacity="0.45"/>
        <rect x="8.8" y="16" width="8.4" height="4.5" rx="1.3" fill="#0b0f0d" fill-opacity="0.3"/>
        <rect x="4.3" y="7" width="2.3" height="4.5" rx="1.1" fill="#0b0f0d" fill-opacity="0.6"/>
        <rect x="19.4" y="7" width="2.3" height="4.5" rx="1.1" fill="#0b0f0d" fill-opacity="0.6"/>
        <rect x="4.3" y="15" width="2.3" height="4.5" rx="1.1" fill="#0b0f0d" fill-opacity="0.6"/>
        <rect x="19.4" y="15" width="2.3" height="4.5" rx="1.1" fill="#0b0f0d" fill-opacity="0.6"/>
    </svg>
`;

const ICONS = {
    origin: L.divIcon({
        html: PIN_SVG('#34d399'),
        className: '',
        iconSize: [26, 36],
        iconAnchor: [13, 36],
        popupAnchor: [0, -32],
    }),
    destination: L.divIcon({
        html: PIN_SVG('#f87171'),
        className: '',
        iconSize: [26, 36],
        iconAnchor: [13, 36],
        popupAnchor: [0, -32],
    }),
    base: L.divIcon({
        html: PIN_SVG('#f59e0b'),
        className: '',
        iconSize: [26, 36],
        iconAnchor: [13, 36],
        popupAnchor: [0, -32],
    }),
    driver: L.divIcon({
        html: DRIVER_DOT_SVG,
        className: '',
        iconSize: [22, 22],
        iconAnchor: [11, 11],
        popupAnchor: [0, -11],
    }),
    // Distinto de "driver" (el punto celeste de seguimiento en vivo de UN
    // conductor puntual, ver Ride/Show.vue) — este es para listas de varios
    // candidatos/disponibles a la vez (Ride/Request.vue, Admin/Drivers.vue).
    car: L.divIcon({
        html: CAR_SVG(),
        className: '',
        iconSize: [26, 26],
        iconAnchor: [13, 13],
        popupAnchor: [0, -13],
    }),
};

// Un ícono de auto por color (pedido explícito del usuario: distinguir de un
// vistazo conductores de flota/cooperativa/públicos en el mapa) — se arma
// una sola vez por color y se reusa, en vez de crear un `L.divIcon` nuevo en
// cada repintado. Con rumbo (`rotation`, pedido explícito del usuario: "que
// el carro gire igual que en el mockup"), NO se cachea — cambia con cada
// posición nueva del conductor en vivo, cachear por cada grado posible no
// tendría sentido (nunca se repetiría el mismo ícono).
const carIconsByColor = new Map();
function carIcon(color, rotation = null) {
    if (rotation != null) {
        return L.divIcon({
            html: `<div style="transform: rotate(${rotation}deg); transform-origin: 50% 50%;">${CAR_SVG(color)}</div>`,
            className: '',
            iconSize: [26, 26],
            iconAnchor: [13, 13],
            popupAnchor: [0, -13],
        });
    }

    if (!carIconsByColor.has(color)) {
        carIconsByColor.set(
            color,
            L.divIcon({
                html: CAR_SVG(color),
                className: '',
                iconSize: [26, 26],
                iconAnchor: [13, 13],
                popupAnchor: [0, -13],
            })
        );
    }

    return carIconsByColor.get(color);
}

function drawMarkers() {
    if (!markerLayer) return;

    markerLayer.clearLayers();

    const validMarkers = props.markers.filter((marker) => marker.lat != null && marker.lng != null);

    validMarkers.forEach((marker) => {
        // Auto de color propio (marker.color, ej. verde de flota / amarillo
        // de cooperativa / azul público) le gana al ícono fijo de "car" — ver
        // carIcon() arriba.
        const markerType = marker.type ?? marker.id;
        const options = markerType === 'car' && marker.color ? { icon: carIcon(marker.color, marker.rotation ?? null) } : ICONS[markerType] ? { icon: ICONS[markerType] } : {};

        // Bug reportado por el usuario ("si moví en el mapa no se
        // recalculó"): un marcador de Leaflet es interactivo por
        // default y se queda con el clic (para su propio popup) en vez
        // de dejarlo pasar al mapa — en una pantalla clickeable (elegir
        // origen/destino), volver a tocar justo donde ya está el pin
        // para reubicarlo nunca llegaba a `map-click`. Sin popup propio
        // que perder acá (el label ya se ve en el propio formulario), se
        // desactiva la interactividad del pin para que el clic siempre
        // le llegue al mapa.
        //
        // Bug real reportado por el usuario ("si le doy encima a un
        // conductor cree que es la ubicación que quiero"): esto se
        // aplicaba a CUALQUIER marcador con el mapa en modo clickeable,
        // así que un auto de "conductores cerca" (Dashboard.vue,
        // Ride/Request.vue) también se volvía transparente al clic y
        // terminaba fijando esa posición como destino. Acotado a los
        // pines de origen/destino — los únicos pensados para reubicarse
        // tocando encima —, los autos siguen interactivos y absorben el
        // clic en vez de dejarlo pasar.
        if (props.clickable && (marker.id === 'origin' || marker.id === 'destination')) {
            options.interactive = false;
        }

        const leafletMarker = L.marker([marker.lat, marker.lng], options).addTo(markerLayer);

        if (!props.clickable) {
            leafletMarker.bindPopup(marker.label ?? '');
        }
    });

    const fitMarkers = props.fitMarkerIds.length
        ? validMarkers.filter((marker) => props.fitMarkerIds.includes(marker.id))
        : validMarkers;

    if (props.autoFit && fitMarkers.length && map) {
        // Firma de composición: cuántos marcadores hay y de qué id (no la
        // posición) — un ping en vivo que solo mueve al mismo conductor no
        // cambia esto, pero marcar el destino después del origen sí.
        const signature = props.fitMarkerIds.length
            ? fitMarkers.map((marker) => `${marker.id}:${Number(marker.lat).toFixed(5)},${Number(marker.lng).toFixed(5)}`).sort().join('|')
            : `${fitMarkers.length}:${fitMarkers.map((m) => m.id ?? '').sort().join(',')}`;

        if (signature !== lastFitSignature) {
            lastFitSignature = signature;

            if (fitMarkers.length === 1) {
                map.setView([fitMarkers[0].lat, fitMarkers[0].lng], props.zoom);
            } else {
                map.fitBounds(L.latLngBounds(fitMarkers.map((m) => [m.lat, m.lng])), {
                    paddingTopLeft: [40, props.fitPaddingTop],
                    paddingBottomRight: [40, props.fitPaddingBottom],
                    maxZoom: 16,
                });
            }
        }
    }
}

function drawRoute() {
    if (!map) return;

    if (routeLine) {
        routeLine.remove();
        routeLine = null;
    }

    if (!props.route.length) return;

    routeLine = L.polyline(
        props.route.map((p) => [p.lat, p.lng]),
        { color: '#34d399', weight: 4, opacity: 0.8 }
    ).addTo(map);
}

// Redibuja los marcadores cuando cambian (ubicación en vivo vía Echo, sección 9.3).
watch(() => props.markers, drawMarkers, { deep: true });
watch(() => props.route, drawRoute, { deep: true });

// Recentra el mapa si el centro cambia después de montado (ej. el cliente
// elige otra ciudad al pedir una carrera, consideración agregada al alcance).
watch(
    () => [props.center?.lat, props.center?.lng],
    ([lat, lng]) => {
        if (lat != null && lng != null) applyView(lat, lng, props.zoom);
    }
);

defineExpose({
    // Permite que la página centre el mapa en la ubicación del usuario una
    // vez que el navegador se la da (ej. al elegir origen de una carrera).
    setView: applyView,
});
</script>

<template>
    <!-- Bug real reportado por el usuario, con capturas repetidas — encontrada
         la causa de verdad: Leaflet le pone z-index PROPIO a sus capas
         internas (marcadores 600, popups 700, controles 1000 — valores fijos
         de su propio CSS), y este contenedor no armaba su propio contexto de
         apilamiento — sin eso, esos números compiten directo contra
         cualquier tarjeta que flote encima del mapa desde afuera (ej. la de
         origen/destino en Ride/Request.vue, con z-10), y 600/700/1000 le
         ganan a 10 así el DOM diga que la tarjeta "debería" quedar arriba.
         `isolate` (aísla, sin necesitar un z-index numérico más alto)
         encierra todos esos valores adentro del propio mapa — desde afuera,
         el mapa completo se comporta como si tuviera z-index 0, y cualquier
         tarjeta que flote encima gana siempre. -->
    <div ref="mapEl" class="w-full overflow-hidden isolate" :class="rounded ? 'rounded-arka' : ''" :style="{ height }"></div>
</template>

<style>
/* Atribución de OpenStreetMap/CARTO (pedido explícito del usuario: "el
   fondo oscuro no hace contraste, usa un gris") — no se puede quitar del
   todo (condición de uso de los tiles gratuitos, ver sección 9.3 del
   alcance), pero sí se puede hacer chica y discreta en vez de la barra
   blanca opaca por defecto de Leaflet. Sin `scoped`: Leaflet arma este
   elemento por su cuenta, fuera del árbol que Vue conoce, así que un estilo
   con alcance no lo alcanzaría — `.leaflet-control-attribution` es un
   nombre propio de Leaflet, no choca con nada más de la app. */
.leaflet-control-attribution {
    background: rgba(17, 24, 39, 0.55) !important;
    color: rgba(255, 255, 255, 0.6) !important;
    font-size: 9px !important;
    line-height: 1.4 !important;
    padding: 1px 6px !important;
}
.leaflet-control-attribution a {
    color: rgba(255, 255, 255, 0.8) !important;
}
</style>
