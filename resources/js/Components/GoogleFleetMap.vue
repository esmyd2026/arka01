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
});

const emit = defineEmits(['map-click']);
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

const svgUrl = (svg) => `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
const pinSvg = (color) => `<svg width="30" height="40" viewBox="0 0 26 36" xmlns="http://www.w3.org/2000/svg"><path d="M13 0C5.8 0 0 5.8 0 13c0 9.75 13 23 13 23s13-13.25 13-23C26 5.8 20.2 0 13 0z" fill="${color}" stroke="#0b0f0d"/><circle cx="13" cy="13" r="5" fill="#0b0f0d" fill-opacity=".3"/></svg>`;
const carSvg = (color = '#34d399', rotation = 0) => `<svg width="32" height="32" viewBox="0 0 26 26" xmlns="http://www.w3.org/2000/svg"><g transform="rotate(${rotation} 13 13)"><rect x="7" y="2.5" width="12" height="21" rx="4.5" fill="${color}" stroke="#0b0f0d"/><rect x="8.8" y="5.5" width="8.4" height="4.5" rx="1.3" fill="#0b0f0d" fill-opacity=".45"/><rect x="8.8" y="16" width="8.4" height="4.5" rx="1.3" fill="#0b0f0d" fill-opacity=".3"/></g></svg>`;

function markerIcon(marker) {
    const type = marker.type ?? marker.id;
    if (type === 'origin') return { url: svgUrl(pinSvg('#34d399')), scaledSize: new google.maps.Size(30, 40), anchor: new google.maps.Point(15, 40) };
    if (type === 'destination') return { url: svgUrl(pinSvg('#f87171')), scaledSize: new google.maps.Size(30, 40), anchor: new google.maps.Point(15, 40) };
    if (type === 'base') return { url: svgUrl(pinSvg('#f59e0b')), scaledSize: new google.maps.Size(30, 40), anchor: new google.maps.Point(15, 40) };
    return { url: svgUrl(carSvg(marker.color ?? '#34d399', marker.rotation ?? 0)), scaledSize: new google.maps.Size(32, 32), anchor: new google.maps.Point(16, 16) };
}

function applyView(lat, lng, zoom = props.zoom) {
    if (!map || lat == null || lng == null) return;
    map.setCenter({ lat: Number(lat), lng: Number(lng) });
    map.setZoom(zoom);
    if (props.centerOffsetY) window.setTimeout(() => map?.panBy(0, props.centerOffsetY / 2), 0);
}

function drawMarkers() {
    if (!map) return;
    renderedMarkers.forEach((marker) => marker.setMap(null));
    renderedMarkers = [];

    const valid = props.markers.filter((marker) => marker.lat != null && marker.lng != null);
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
        styles: props.dark ? DARK_STYLES : LIGHT_STYLES,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
        clickableIcons: false,
        gestureHandling: 'greedy',
        zoomControlOptions: { position: google.maps.ControlPosition.LEFT_TOP },
    });
    if (props.clickable) listeners.push(map.addListener('click', (event) => emit('map-click', { lat: event.latLng.lat(), lng: event.latLng.lng() })));
    drawMarkers();
    drawRoute();
});

watch(() => props.markers, drawMarkers, { deep: true });
watch(() => props.route, drawRoute, { deep: true });
watch(() => [props.center?.lat, props.center?.lng], ([lat, lng]) => applyView(lat, lng, props.zoom));
watch(() => props.dark, (dark) => map?.setOptions({ styles: dark ? DARK_STYLES : LIGHT_STYLES }));

onBeforeUnmount(() => {
    listeners.forEach((listener) => listener.remove());
    renderedMarkers.forEach((marker) => marker.setMap(null));
    routeLine?.setMap(null);
    map = null;
});

defineExpose({ setView: applyView });
</script>

<template>
    <div ref="mapEl" class="w-full overflow-hidden isolate" :class="rounded ? 'rounded-arka' : ''" :style="{ height, '--controls-top-offset': controlsTopOffset }"></div>
</template>

<style scoped>
:deep(.gm-bundled-control) { margin-top: var(--controls-top-offset, 0px) !important; }
</style>
