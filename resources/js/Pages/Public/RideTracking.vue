<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import FleetMap from '@/Components/FleetMap.vue';

// Página pública (sección 8: "de solo lectura, sin cuenta ni instalación").
// No usa AuthenticatedLayout a propósito: quien la abre no tiene sesión.
const props = defineProps({
    ride: { type: Object, required: true },
    statusUrl: { type: String, required: true },
});

const current = ref(props.ride);
let poller = null;

const STATUS_LABELS = {
    in_progress: 'En curso',
    completed: 'Finalizada',
    cancelled: 'Cancelada',
};

async function refresh() {
    try {
        const response = await fetch(props.statusUrl, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        current.value = await response.json();
    } catch {
        // Si falla un ping puntual (red intermitente), simplemente se reintenta en el próximo tick.
    }
}

onMounted(() => {
    // Mientras el viaje sigue en curso, se refresca solo cada 8s — no hace
    // falta WebSocket para un enlace de "chequeá cada tanto" (sección 8).
    if (current.value.status === 'in_progress') {
        poller = setInterval(() => {
            refresh();
            if (current.value.status !== 'in_progress' && poller) {
                clearInterval(poller);
            }
        }, 8000);
    }
});

onBeforeUnmount(() => {
    if (poller) clearInterval(poller);
});

const mapMarkers = computed(() => {
    const markers = [
        { id: 'origin', lat: Number(current.value.origin_lat), lng: Number(current.value.origin_lng), label: 'Origen' },
        {
            id: 'destination',
            lat: Number(current.value.destination_lat),
            lng: Number(current.value.destination_lng),
            label: 'Destino',
        },
    ];

    if (current.value.driver_lat != null) {
        markers.push({
            id: 'driver',
            lat: Number(current.value.driver_lat),
            lng: Number(current.value.driver_lng),
            label: current.value.driver_name,
        });
    }

    return markers;
});
</script>

<template>
    <Head title="Seguimiento en vivo" />

    <div class="min-h-screen bg-arka-base flex flex-col">
        <header class="bg-arka-card border-b border-arka-text-muted/10 px-4 py-3">
            <p class="text-arka-text font-semibold">Arka01 · Seguimiento en vivo</p>
            <p class="text-sm text-arka-text-muted">
                Conductor: {{ current.driver_name }}
                <span v-if="current.vehicle"> · {{ current.vehicle }}</span>
                <span v-if="current.vehicle_plate"> · Placa {{ current.vehicle_plate }}</span>
            </p>
        </header>

        <main class="flex-1 p-4 max-w-2xl w-full mx-auto space-y-4">
            <div class="p-4 bg-arka-card rounded-arka shadow flex items-center justify-between">
                <span class="text-arka-text-muted">Estado</span>
                <span class="text-arka-text font-medium">{{ STATUS_LABELS[current.status] ?? current.status }}</span>
            </div>

            <div class="bg-arka-card rounded-arka shadow overflow-hidden">
                <FleetMap :markers="mapMarkers" height="380px" />
            </div>

            <p v-if="current.status === 'in_progress'" class="text-xs text-arka-text-muted text-center">
                Este mapa se actualiza solo cada pocos segundos.
            </p>
            <p v-else class="text-xs text-arka-text-muted text-center">
                El viaje ya terminó — esta es la última posición registrada.
            </p>
        </main>
    </div>
</template>
