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
    autoFit: { type: Boolean, default: true },
    fitMarkerIds: { type: Array, default: () => [] },
    fitPaddingTop: { type: Number, default: 40 },
    fitPaddingBottom: { type: Number, default: 40 },
    rounded: { type: Boolean, default: true },
    dark: { type: Boolean, default: true },
    controlsTopOffset: { type: String, default: '0px' },
    centerOffsetY: { type: Number, default: 0 },
});

const emit = defineEmits(['map-click', 'user-panned']);
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
    <GoogleFleetMap v-else-if="implementation === 'google'" ref="mapRef" v-bind="$props" @map-click="emit('map-click', $event)" @user-panned="emit('user-panned')" />
    <LeafletFleetMap v-else ref="mapRef" v-bind="$props" @map-click="emit('map-click', $event)" @user-panned="emit('user-panned')" />
</template>
