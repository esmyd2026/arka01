<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    markers: { type: Array, default: () => [] },
    center: { type: Object, default: () => ({ lat: -0.1807, lng: -78.4678 }) },
    zoom: { type: Number, default: 13 },
    height: { type: String, default: '320px' },
    clickable: { type: Boolean, default: false },
    route: { type: Array, default: () => [] },
    autoFit: { type: Boolean, default: true },
    fitMarkerIds: { type: Array, default: () => [] },
    fitPaddingTop: { type: Number, default: 40 },
    fitPaddingBottom: { type: Number, default: 40 },
    rounded: { type: Boolean, default: true },
    dark: { type: Boolean, default: true },
    controlsTopOffset: { type: String, default: '0px' },
    centerOffsetY: { type: Number, default: 0 },
    // Rediseño puramente visual del mapa de Inicio (pedido explícito del
    // usuario: "que parezca una aplicación propia de ARKA01, no un mapa de
    // Google con un formulario debajo"). Ambas son opt-in con default que
    // preserva el estilo de siempre — ninguna otra pantalla que usa este
    // componente (Ride/Request.vue, Ride/Show.vue, etc.) cambia de aspecto.
    minimalStyle: { type: Boolean, default: false },
    originMarkerStyle: { type: String, default: 'pin' }, // 'pin' | 'dot'
});

const emit = defineEmits(['map-click', 'user-panned']);
const mapEl = ref(null);
let map = null;
let routeLine = null;
let renderedMarkers = [];
let listeners = [];
let lastFitSignature = null;

const DARK_STYLES = [
    { elementType: 'geometry', stylers: [{ color: '#17211d' }] },
    { elementType: 'labels.text.stroke', stylers: [{ color: '#17211d' }] },
    { elementType: 'labels.text.fill', stylers: [{ color: '#91a79d' }] },
    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', stylers: [{ visibility: 'off' }] },
    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#2b3a34' }] },
    { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#101713' }] },
    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0b1411' }] },
];

// Base clara y limpia para movilidad: conserva la red vial, barrios y
// referencias geográficas importantes, pero elimina iconos comerciales,
// turísticos y de transporte que compiten con los vehículos de Arka01.
const LIGHT_STYLES = [
    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', stylers: [{ visibility: 'off' }] },
    { featureType: 'road', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
    { featureType: 'administrative.neighborhood', elementType: 'labels', stylers: [{ visibility: 'simplified' }] },
    { featureType: 'landscape', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#e6ebe8' }] },
    { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#d8e0dc' }] },
    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#dce5e7' }] },
    { elementType: 'labels.text.fill', stylers: [{ color: '#66756e' }] },
    { elementType: 'labels.text.stroke', stylers: [{ color: '#f4f7f5' }, { weight: 2 }] },
];

// Variante "premium" solo para Inicio (pedido explícito del usuario, opt-in
// vía la prop `minimalStyle` — el resto de pantallas sigue usando
// LIGHT_STYLES de arriba sin cambios). Mismo criterio que LIGHT_STYLES
// (oculta POI/transporte que compiten visualmente con los vehículos) pero
// con la paleta puntual que pidió: calles casi blancas, agua/parques/
// edificios diferenciados por tono, sin dominar con el verde de la marca.
const MINIMAL_LIGHT_STYLES = [
    { elementType: 'geometry', stylers: [{ color: '#F6F8F7' }] },
    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
    { featureType: 'poi.park', elementType: 'geometry', stylers: [{ visibility: 'on' }, { color: '#E8F2EC' }] },
    { featureType: 'transit', stylers: [{ visibility: 'off' }] },
    { featureType: 'road', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
    { featureType: 'administrative.neighborhood', elementType: 'labels', stylers: [{ visibility: 'simplified' }] },
    { featureType: 'landscape', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
    { featureType: 'landscape.man_made', elementType: 'geometry', stylers: [{ color: '#EEF1F0' }] },
    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#FFFFFF' }] },
    { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#E5E9E7' }] },
    { featureType: 'road.local', elementType: 'geometry', stylers: [{ color: '#F9FAFA' }] },
    { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#E4EAE7' }] },
    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#DDE9EA' }] },
    { elementType: 'labels.text.fill', stylers: [{ color: '#65716D' }] },
    { elementType: 'labels.text.stroke', stylers: [{ color: '#F6F8F7' }, { weight: 2 }] },
    { featureType: 'administrative', elementType: 'labels.text.fill', stylers: [{ color: '#98A29E' }] },
];

const svgUrl = (svg) => `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
const pinSvg = (color) => `<svg width="30" height="40" viewBox="0 0 26 36" xmlns="http://www.w3.org/2000/svg"><path d="M13 0C5.8 0 0 5.8 0 13c0 9.75 13 23 13 23s13-13.25 13-23C26 5.8 20.2 0 13 0z" fill="${color}" stroke="#0b0f0d"/><circle cx="13" cy="13" r="5" fill="#0b0f0d" fill-opacity=".3"/></svg>`;
// Punto GPS moderno (pedido explícito del usuario: nada de pin verde grande
// para "mi ubicación" en Inicio) — círculo sólido con borde blanco y una
// sombra suave, mismo lenguaje que cualquier app de mapas actual.
const dotSvg = (color) => `<svg width="30" height="30" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg"><defs><filter id="d" x="-50%" y="-50%" width="200%" height="200%"><feDropShadow dx="0" dy="1" stdDeviation="1.6" flood-color="#0b0f0d" flood-opacity="0.35"/></filter></defs><circle cx="15" cy="15" r="9" fill="#ffffff" filter="url(#d)"/><circle cx="15" cy="15" r="6.5" fill="${color}"/></svg>`;
const carSvg = (color = '#34d399', rotation = 0) => `<svg width="32" height="32" viewBox="0 0 26 26" xmlns="http://www.w3.org/2000/svg"><g transform="rotate(${rotation} 13 13)"><rect x="7" y="2.5" width="12" height="21" rx="4.5" fill="${color}" stroke="#0b0f0d"/><rect x="8.8" y="5.5" width="8.4" height="4.5" rx="1.3" fill="#0b0f0d" fill-opacity=".45"/><rect x="8.8" y="16" width="8.4" height="4.5" rx="1.3" fill="#0b0f0d" fill-opacity=".3"/></g></svg>`;
// Vehículo visto desde arriba (pedido explícito del usuario: "no quiero
// iconos azules rectangulares tipo carro genérico... quiero un vehículo
// elegante y minimalista"). Solo para Inicio (`minimalStyle`) — el resto de
// pantallas sigue con `carSvg` de arriba, sin cambios. Blanco, parabrisas/
// luneta oscuros, un detalle verde ARKA discreto, sombra suave debajo (fuera
// del grupo que rota, como corresponde a una sombra proyectada en el suelo).
const carSvgPremium = (color = '#19B982', rotation = 0) => `<svg width="36" height="36" viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg"><ellipse cx="18" cy="29" rx="8.5" ry="2.6" fill="#0b0f0d" fill-opacity=".16"/><g transform="rotate(${rotation} 18 17)"><path d="M18 4c4 0 6.1 1.8 6.9 5.7l1 5c.4 1.9.4 3.9 0 5.8l-.8 4c-.5 2.2-1.8 3.1-3.6 3.1H14.5c-1.8 0-3.1-.9-3.6-3.1l-.8-4a15 15 0 0 1 0-5.8l1-5C11.9 5.8 14 4 18 4Z" fill="#FFFFFF" stroke="#C7CCC9" stroke-width="1"/><rect x="12" y="9.6" width="12" height="6" rx="2.1" fill="#242B29" fill-opacity=".85"/><rect x="12.7" y="19.6" width="10.6" height="5" rx="1.8" fill="#242B29" fill-opacity=".5"/><circle cx="18" cy="7" r="1.1" fill="${color}"/></g></svg>`;

function markerIcon(marker) {
    const type = marker.type ?? marker.id;
    if (type === 'origin') {
        if (props.originMarkerStyle === 'dot') {
            return { url: svgUrl(dotSvg('#19B982')), scaledSize: new google.maps.Size(30, 30), anchor: new google.maps.Point(15, 15) };
        }
        return { url: svgUrl(pinSvg('#34d399')), scaledSize: new google.maps.Size(30, 40), anchor: new google.maps.Point(15, 40) };
    }
    if (type === 'destination') return { url: svgUrl(pinSvg('#f87171')), scaledSize: new google.maps.Size(30, 40), anchor: new google.maps.Point(15, 40) };
    if (type === 'base') return { url: svgUrl(pinSvg('#f59e0b')), scaledSize: new google.maps.Size(30, 40), anchor: new google.maps.Point(15, 40) };
    if (props.minimalStyle) {
        return { url: svgUrl(carSvgPremium(marker.color ?? '#19B982', marker.rotation ?? 0)), scaledSize: new google.maps.Size(34, 34), anchor: new google.maps.Point(17, 17) };
    }
    return { url: svgUrl(carSvg(marker.color ?? '#34d399', marker.rotation ?? 0)), scaledSize: new google.maps.Size(32, 32), anchor: new google.maps.Point(16, 16) };
}

function applyView(lat, lng, zoom = props.zoom) {
    if (!map || lat == null || lng == null) return;
    map.setCenter({ lat: Number(lat), lng: Number(lng) });
    map.setZoom(zoom);
    if (props.centerOffsetY) window.setTimeout(() => map?.panBy(0, props.centerOffsetY / 2), 0);
}

// Comportamiento de siempre, intacto: destruye y vuelve a crear todos los
// marcadores en cada actualización — lo sigue usando cualquier pantalla que
// no pase `minimalStyle` (Ride/Request.vue, Ride/Show.vue, etc.).
function syncMarkersInstant(valid) {
    renderedMarkers.forEach((marker) => marker.setMap(null));
    renderedMarkers = [];

    valid.forEach((item) => {
        renderedMarkers.push(new google.maps.Marker({
            map,
            position: { lat: Number(item.lat), lng: Number(item.lng) },
            title: item.label ?? '',
            icon: markerIcon(item),
            clickable: !props.clickable || !['origin', 'destination'].includes(item.id),
            optimized: false,
        }));
    });
}

// Movimiento suave (pedido explícito del usuario, solo Inicio vía
// `minimalStyle`): en vez de borrar y recrear el marcador en cada ping de
// ubicación (lo que hacía "saltar" de un punto a otro), reutiliza la MISMA
// instancia de Marker por id y anima su posición y rumbo entre el valor
// viejo y el nuevo. No toca de dónde vienen esas coordenadas ni con qué
// frecuencia llegan — solo cómo se dibuja la transición entre una y otra.
const ANIMATION_MS = 700;
const animatedMarkers = new Map(); // id -> { marker, lat, lng, rotation, raf }

function bearingBetween(lat1, lng1, lat2, lng2) {
    const toRad = (d) => (d * Math.PI) / 180;
    const dLng = toRad(lng2 - lng1);
    const y = Math.sin(dLng) * Math.cos(toRad(lat2));
    const x = Math.cos(toRad(lat1)) * Math.sin(toRad(lat2)) - Math.sin(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.cos(dLng);
    return ((Math.atan2(y, x) * 180) / Math.PI + 360) % 360;
}

// Nunca gira "por el camino largo" (ej. de 350° a 10° son 20°, no 340°).
function shortestDeltaAngle(from, to) {
    let delta = (to - from) % 360;
    if (delta > 180) delta -= 360;
    if (delta < -180) delta += 360;
    return delta;
}

const easeOutCubic = (t) => 1 - (1 - t) ** 3;

function animateMarkerTo(state, item) {
    if (state.raf) cancelAnimationFrame(state.raf);

    const fromLat = state.lat;
    const fromLng = state.lng;
    const toLat = Number(item.lat);
    const toLng = Number(item.lng);
    const fromRotation = state.rotation;
    // Si el marcador ya trae su propio rumbo real (ej. Ride/Show.vue,
    // `carHeading` calculado entre las dos últimas posiciones GPS conocidas
    // del conductor asignado), se respeta ESE valor — no se recalcula uno
    // propio. Solo cuando no viene ninguno (ej. Inicio, lista de conductores
    // cercanos sin heading) se deriva del propio desplazamiento del
    // marcador, y solo si de verdad se movió (evita que tiemble girando por
    // ruido de GPS con el conductor detenido).
    const movedMeaningfully = Math.abs(toLat - fromLat) > 0.00003 || Math.abs(toLng - fromLng) > 0.00003;
    const toRotation = item.rotation != null
        ? item.rotation
        : (movedMeaningfully ? bearingBetween(fromLat, fromLng, toLat, toLng) : fromRotation);
    const deltaRotation = shortestDeltaAngle(fromRotation, toRotation);
    const start = performance.now();
    let lastIconUpdate = 0;

    const step = (now) => {
        const t = Math.min(1, (now - start) / ANIMATION_MS);
        const eased = easeOutCubic(t);
        const lat = fromLat + (toLat - fromLat) * eased;
        const lng = fromLng + (toLng - fromLng) * eased;
        const rotation = fromRotation + deltaRotation * eased;

        state.lat = lat;
        state.lng = lng;
        state.rotation = rotation;
        state.marker.setPosition({ lat, lng });

        // Regenerar el ícono en cada frame decodificaría un SVG nuevo hasta
        // 60 veces por segundo por auto — de sobra para el ojo, de más para
        // el navegador. Cada ~100ms alcanza para ver el giro suave.
        if (now - lastIconUpdate > 100 || t === 1) {
            state.marker.setIcon(markerIcon({ id: item.id, type: item.type, color: item.color, rotation }));
            lastIconUpdate = now;
        }

        state.raf = t < 1 ? requestAnimationFrame(step) : null;
    };

    state.raf = requestAnimationFrame(step);
}

function syncMarkersAnimated(valid) {
    const seen = new Set();

    valid.forEach((item) => {
        seen.add(item.id);
        const lat = Number(item.lat);
        const lng = Number(item.lng);
        const state = animatedMarkers.get(item.id);

        if (!state) {
            const marker = new google.maps.Marker({
                map,
                position: { lat, lng },
                title: item.label ?? '',
                icon: markerIcon(item),
                clickable: !props.clickable || !['origin', 'destination'].includes(item.id),
                optimized: false,
            });
            animatedMarkers.set(item.id, { marker, lat, lng, rotation: 0, raf: null });
            return;
        }

        if (Math.abs(state.lat - lat) > 1e-9 || Math.abs(state.lng - lng) > 1e-9) {
            animateMarkerTo(state, item);
        }
    });

    Array.from(animatedMarkers.keys()).forEach((id) => {
        if (seen.has(id)) return;
        const state = animatedMarkers.get(id);
        if (state.raf) cancelAnimationFrame(state.raf);
        state.marker.setMap(null);
        animatedMarkers.delete(id);
    });
}

// Mismo criterio de encuadre de siempre, sin cambios — solo se movió a su
// propia función para que la puedan llamar los dos caminos de arriba.
function applyAutoFit(valid) {
    const fitMarkers = props.fitMarkerIds.length
        ? valid.filter((item) => props.fitMarkerIds.includes(item.id))
        : valid;
    if (!props.autoFit || !fitMarkers.length) return;
    const signature = props.fitMarkerIds.length
        ? fitMarkers.map((item) => `${item.id}:${Number(item.lat).toFixed(5)},${Number(item.lng).toFixed(5)}`).sort().join('|')
        : `${fitMarkers.length}:${fitMarkers.map((item) => item.id ?? '').sort().join(',')}`;
    if (signature === lastFitSignature) return;
    lastFitSignature = signature;

    if (fitMarkers.length === 1) return applyView(fitMarkers[0].lat, fitMarkers[0].lng, props.zoom);

    const bounds = new google.maps.LatLngBounds();
    fitMarkers.forEach((item) => bounds.extend({ lat: Number(item.lat), lng: Number(item.lng) }));
    map.fitBounds(bounds, { top: props.fitPaddingTop, right: 40, bottom: props.fitPaddingBottom, left: 40 });
    google.maps.event.addListenerOnce(map, 'idle', () => {
        if ((map?.getZoom() ?? 0) > 16) map.setZoom(16);
    });
}

function drawMarkers() {
    if (!map) return;
    const valid = props.markers.filter((marker) => marker.lat != null && marker.lng != null);

    if (props.minimalStyle) {
        syncMarkersAnimated(valid);
    } else {
        syncMarkersInstant(valid);
    }

    applyAutoFit(valid);
}

function drawRoute() {
    routeLine?.setMap(null);
    routeLine = null;
    if (!map || !props.route.length) return;
    routeLine = new google.maps.Polyline({
        map,
        path: props.route.map((point) => ({ lat: Number(point.lat), lng: Number(point.lng) })),
        strokeColor: '#34d399',
        strokeOpacity: 0.9,
        strokeWeight: 5,
    });
}

onMounted(() => {
    map = new google.maps.Map(mapEl.value, {
        center: { lat: Number(props.center.lat), lng: Number(props.center.lng) },
        zoom: props.zoom,
        styles: props.dark ? DARK_STYLES : (props.minimalStyle ? MINIMAL_LIGHT_STYLES : LIGHT_STYLES),
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
        clickableIcons: false,
        gestureHandling: 'greedy',
        zoomControlOptions: { position: google.maps.ControlPosition.LEFT_TOP },
    });
    if (props.clickable) listeners.push(map.addListener('click', (event) => emit('map-click', { lat: event.latLng.lat(), lng: event.latLng.lng() })));

    // Bug real encontrado (pedido explícito del usuario: "el conductor no
    // puede mover el mapa"): la app tiene configurada la API key de Google
    // Maps (VITE_GOOGLE_MAPS_API_KEY), así que ESTA implementación es la que
    // corre de verdad — la pausa de seguimiento al arrastrar (Ride/Show.vue,
    // followDriver) se había agregado solo en LeafletFleetMap.vue, que en la
    // práctica nunca se usa acá. `dragstart` de Google Maps, igual que el de
    // Leaflet, solo dispara con un arrastre real del usuario, nunca con
    // `setCenter()`/`fitBounds()` programáticos.
    listeners.push(map.addListener('dragstart', () => emit('user-panned')));

    drawMarkers();
    drawRoute();
});

watch(() => props.markers, drawMarkers, { deep: true });
watch(() => props.route, drawRoute, { deep: true });
watch(() => [props.center?.lat, props.center?.lng], ([lat, lng]) => applyView(lat, lng, props.zoom));
// Bug real reportado por el usuario (captura de un iPhone: "no se nota el
// mapa con la ubicación"): `center` casi siempre ya está fijado ANTES de que
// el bottom sheet termine de medir su alto real (Dashboard.vue lo mide con
// ResizeObserver después del primer render) — sin este watcher, ese primer
// applyView() corría con centerOffsetY todavía en 0 (sin correr el punto
// hacia arriba), y como nada volvía a llamar a applyView() cuando el alto
// real llegaba, el punto de "mi ubicación" quedaba centrado en el
// contenedor COMPLETO — pegado al borde de lo que de verdad se alcanza a
// ver, tapado casi entero por el sheet. En Safari además el alto real del
// sheet puede cambiar solo (usa `dvh`, que se recalcula cuando la barra de
// direcciones se oculta/aparece), así que esto también corrige ese caso.
watch(() => props.centerOffsetY, () => applyView(props.center?.lat, props.center?.lng, props.zoom));
watch(() => props.dark, (dark) => map?.setOptions({ styles: dark ? DARK_STYLES : (props.minimalStyle ? MINIMAL_LIGHT_STYLES : LIGHT_STYLES) }));

onBeforeUnmount(() => {
    listeners.forEach((listener) => listener.remove());
    renderedMarkers.forEach((marker) => marker.setMap(null));
    animatedMarkers.forEach((state) => {
        if (state.raf) cancelAnimationFrame(state.raf);
        state.marker.setMap(null);
    });
    animatedMarkers.clear();
    routeLine?.setMap(null);
    map = null;
});

// Pedido explícito del usuario ("es muy mínimo y no se logra detallar la
// ruta en la que voy"): centrar solo en la posición del conductor con un
// zoom fijo dejaba el destino/próxima parada fuera de vista si quedaba
// lejos, o de más cerca de lo necesario si ya estaba encima — encuadra los
// dos puntos juntos cada vez, para que siempre se vea el tramo que falta,
// no un zoom arbitrario. `maxZoom` evita que se acerque demasiado si el
// conductor ya está casi encima del objetivo.
function fitTo(points, { maxZoom = 17 } = {}) {
    if (!map || points.length < 2) return;
    const bounds = new google.maps.LatLngBounds();
    points.forEach((point) => bounds.extend({ lat: Number(point.lat), lng: Number(point.lng) }));
    map.fitBounds(bounds, { top: 60, right: 60, bottom: 60, left: 60 });
    google.maps.event.addListenerOnce(map, 'idle', () => {
        if ((map?.getZoom() ?? 0) > maxZoom) map.setZoom(maxZoom);
    });
}

defineExpose({ setView: applyView, fitTo });
</script>

<template>
    <div ref="mapEl" class="w-full overflow-hidden isolate" :class="rounded ? 'rounded-arka' : ''" :style="{ height, '--controls-top-offset': controlsTopOffset }"></div>
</template>

<style scoped>
:deep(.gm-bundled-control) { margin-top: var(--controls-top-offset, 0px) !important; }
</style>
