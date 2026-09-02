<script setup>
import { onMounted, ref } from 'vue';
import GoogleFleetMap from '@/Components/GoogleFleetMap.vue';
import LeafletFleetMap from '@/Components/LeafletFleetMap.vue';
import { loadGoogleMaps } from '@/Utils/googleMaps';

defineProps({
    markers: { type: Array, default: () => [] },
    center: { type: Object, default: () => ({ lat: -0.1807, lng: -78.4678 }) },
    zoom: { type: Number, default: 13 },
    height: { type: String, default: '320px' },
    clickable: { type: Boolean, default: false },
    route: { type: Array, default: () => [] },
    animateRoute: { type: Boolean, default: false },
    autoFit: { type: Boolean, default: true },
    fitMarkerIds: { type: Array, default: () => [] },
    fitPaddingTop: { type: Number, default: 40 },
    fitPaddingBottom: { type: Number, default: 40 },
    rounded: { type: Boolean, default: true },
    dark: { type: Boolean, default: true },
    controlsTopOffset: { type: String, default: '0px' },
    centerOffsetY: { type: Number, default: 0 },
    // Rediseño puramente visual de Inicio (ver GoogleFleetMap.vue) — solo
    // tiene efecto ahí, Leaflet (fallback sin key de Google) los ignora.
    minimalStyle: { type: Boolean, default: false },
    originMarkerStyle: { type: String, default: 'pin' },
    destinationMarkerStyle: { type: String, default: 'pin' },
    // Ver GoogleFleetMap.vue — solo tiene efecto ahí, Leaflet lo ignora.
    vehicleStatusRing: { type: Boolean, default: false },
    // Puntos que el usuario puede ajustar arrastrando. Se mantiene vacío por
    // defecto para no volver movibles los marcadores de seguimiento, flotas
    // o administración que reutilizan este componente.
    draggableMarkerIds: { type: Array, default: () => [] },
});

const emit = defineEmits(['map-click', 'user-panned', 'marker-drag-start', 'marker-drag-end']);
const implementation = ref(null);
const mapRef = ref(null);

onMounted(async () => {
    implementation.value = (await loadGoogleMaps()) ? 'google' : 'leaflet';
});

defineExpose({
    setView: (lat, lng, zoom) => mapRef.value?.setView(lat, lng, zoom),
    fitTo: (points, options) => mapRef.value?.fitTo(points, options),
});
</script>

<template>
    <div v-if="!implementation" class="grid w-full place-items-center bg-arka-card text-xs text-arka-text-muted" :class="rounded ? 'rounded-arka' : ''" :style="{ height }">Cargando mapa…</div>
    <GoogleFleetMap v-else-if="implementation === 'google'" ref="mapRef" v-bind="$props" @map-click="emit('map-click', $event)" @user-panned="emit('user-panned')" @marker-drag-start="emit('marker-drag-start', $event)" @marker-drag-end="emit('marker-drag-end', $event)" />
    <LeafletFleetMap v-else ref="mapRef" v-bind="$props" @map-click="emit('map-click', $event)" @user-panned="emit('user-panned')" @marker-drag-start="emit('marker-drag-start', $event)" @marker-drag-end="emit('marker-drag-end', $event)" />
</template>
