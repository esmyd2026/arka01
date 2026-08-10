<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import L from 'leaflet';
import { fixLeafletIcons } from '@/Utils/leafletIcons';

// Mapa reutilizable con Leaflet + OpenStreetMap (sección 9.3: sin costo por uso,
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

onMounted(() => {
    fixLeafletIcons();

    map = L.map(mapEl.value).setView([props.center.lat, props.center.lng], props.zoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);

    markerLayer = L.layerGroup().addTo(map);
    drawMarkers();
    drawRoute();

    if (props.clickable) {
        map.on('click', (e) => emit('map-click', { lat: e.latlng.lat, lng: e.latlng.lng }));
    }
});

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
// los conductores de una lista — ej. candidatos al pedir carrera) — un badge
// circular oscuro (misma familia visual que DRIVER_DOT_SVG) con la
// silueta del auto adentro, en el verde de la marca.
const CAR_SVG = `
    <svg width="26" height="26" viewBox="0 0 26 26" xmlns="http://www.w3.org/2000/svg">
        <circle cx="13" cy="13" r="12" fill="#0b0f0d" fill-opacity="0.5"/>
        <rect x="6" y="4" width="14" height="18" rx="5" fill="#34d399" stroke="#0b0f0d" stroke-width="1"/>
        <rect x="8.5" y="6.5" width="9" height="4.5" rx="1.2" fill="#0b0f0d" fill-opacity="0.4"/>
        <rect x="8.5" y="15" width="9" height="4.5" rx="1.2" fill="#0b0f0d" fill-opacity="0.4"/>
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
        html: CAR_SVG,
        className: '',
        iconSize: [26, 26],
        iconAnchor: [13, 13],
        popupAnchor: [0, -13],
    }),
};

function drawMarkers() {
    if (!markerLayer) return;

    markerLayer.clearLayers();

    const validMarkers = props.markers.filter((marker) => marker.lat != null && marker.lng != null);

    validMarkers.forEach((marker) => {
        const options = ICONS[marker.id] ? { icon: ICONS[marker.id] } : {};

        // Bug reportado por el usuario ("si moví en el mapa no se
        // recalculó"): un marcador de Leaflet es interactivo por
        // default y se queda con el clic (para su propio popup) en vez
        // de dejarlo pasar al mapa — en una pantalla clickeable (elegir
        // origen/destino), volver a tocar justo donde ya está el pin
        // para reubicarlo nunca llegaba a `map-click`. Sin popup propio
        // que perder acá (el label ya se ve en el propio formulario), se
        // desactiva la interactividad del pin para que el clic siempre
        // le llegue al mapa.
        if (props.clickable) {
            options.interactive = false;
        }

        const leafletMarker = L.marker([marker.lat, marker.lng], options).addTo(markerLayer);

        if (!props.clickable) {
            leafletMarker.bindPopup(marker.label ?? '');
        }
    });

    if (props.autoFit && validMarkers.length && map) {
        // Firma de composición: cuántos marcadores hay y de qué id (no la
        // posición) — un ping en vivo que solo mueve al mismo conductor no
        // cambia esto, pero marcar el destino después del origen sí.
        const signature = `${validMarkers.length}:${validMarkers.map((m) => m.id ?? '').sort().join(',')}`;

        if (signature !== lastFitSignature) {
            lastFitSignature = signature;

            if (validMarkers.length === 1) {
                map.setView([validMarkers[0].lat, validMarkers[0].lng], props.zoom);
            } else {
                map.fitBounds(L.latLngBounds(validMarkers.map((m) => [m.lat, m.lng])), { padding: [40, 40] });
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
        if (lat != null && lng != null) map?.setView([lat, lng], props.zoom);
    }
);

defineExpose({
    // Permite que la página centre el mapa en la ubicación del usuario una
    // vez que el navegador se la da (ej. al elegir origen de una carrera).
    setView(lat, lng, zoom = props.zoom) {
        map?.setView([lat, lng], zoom);
    },
});
</script>

<template>
    <div ref="mapEl" class="w-full rounded-arka overflow-hidden" :style="{ height }"></div>
</template>
