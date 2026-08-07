<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import AddressAutocomplete from '@/Components/AddressAutocomplete.vue';
import { Head, Link, router } from '@inertiajs/vue3';

// Buscar Expresos de otros clientes, abiertos a compartir, cuyo origen y
// destino queden cerca de los propios (pedido explícito del usuario: "que se
// unan otras personas en su ruta"). El backend hace el matching por
// cercanía (ExpressRouteCompanionController::discover()).
defineProps({
    routes: { type: Array, required: true },
});

const originAddress = ref('');
const originLat = ref(null);
const originLng = ref(null);
const destinationAddress = ref('');
const destinationLat = ref(null);
const destinationLng = ref(null);

function pickOrigin({ lat, lng }) {
    originLat.value = lat;
    originLng.value = lng;
}

function pickDestination({ lat, lng }) {
    destinationLat.value = lat;
    destinationLng.value = lng;
}

function search() {
    router.get(route('express-companions.discover'), {
        origin_lat: originLat.value,
        origin_lng: originLng.value,
        destination_lat: destinationLat.value,
        destination_lng: destinationLng.value,
    }, { preserveState: true });
}

function requestToJoin(routeId) {
    router.post(route('express-companions.store', routeId), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Compartir un Expreso" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="route('express-routes.index')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                    &larr; Mis Expresos
                </Link>
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Compartir un Expreso</h2>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Busque Expresos que ya publicó otra persona, abiertos a compartir, cuya ruta y horario le calcen —
                    al sumarse, el costo se reparte entre los dos y al conductor le conviene más el viaje.
                </p>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Su origen" />
                            <AddressAutocomplete class="mt-1" v-model="originAddress" @place-selected="pickOrigin" />
                        </div>
                        <div>
                            <InputLabel value="Su destino" />
                            <AddressAutocomplete class="mt-1" v-model="destinationAddress" @place-selected="pickDestination" />
                        </div>
                    </div>
                    <PrimaryButton :disabled="!originLat || !destinationLat" @click="search">Buscar</PrimaryButton>
                </div>

                <p v-if="!routes.length" class="text-sm text-arka-text-muted">
                    Sin resultados todavía — busque su origen y destino arriba para ver Expresos cercanos abiertos a compartir.
                </p>

                <ul v-else class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="r in routes" :key="r.id" class="p-4 sm:p-6 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-arka-text font-medium">{{ r.name }}</p>
                            <p class="text-sm text-arka-text-muted">
                                {{ r.origin_address ?? 'Origen sin referencia' }} &rarr; {{ r.destination_address ?? 'Destino sin referencia' }}
                            </p>
                            <p class="text-sm text-arka-text-muted">
                                Sale {{ r.departure_time }} · ${{ r.price_per_person }}/persona ·
                                a {{ Math.max(r.origin_distance_km, r.destination_distance_km) }} km de su ruta
                            </p>
                        </div>
                        <PrimaryButton class="shrink-0" @click="requestToJoin(r.id)">Pedir unirme</PrimaryButton>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
