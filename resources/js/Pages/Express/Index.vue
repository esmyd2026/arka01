<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import FleetMap from '@/Components/FleetMap.vue';
import AddressAutocomplete from '@/Components/AddressAutocomplete.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    routes: { type: Array, required: true },
});

const DAYS = [
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mié' },
    { value: 4, label: 'Jue' },
    { value: 5, label: 'Vie' },
    { value: 6, label: 'Sáb' },
    { value: 0, label: 'Dom' },
];

const STATUS_LABELS = {
    open: 'Abierto a postulaciones',
    active: 'Activo',
    paused: 'Pausado',
    cancelled: 'Cancelado',
};

const showCreateForm = ref(false);

const form = useForm({
    name: '',
    origin_lat: null,
    origin_lng: null,
    origin_address: '',
    destination_lat: null,
    destination_lng: null,
    destination_address: '',
    days_of_week: [],
    departure_time: '07:00',
    offered_price: null,
    conditions: [],
    // Pedido explícito del usuario: que otras personas con ruta parecida se
    // puedan sumar y repartir el costo, para que al conductor sí le convenga.
    share_enabled: false,
    max_companions: 1,
});

// Toggle simple: "marcando origen" o "marcando destino" en el mismo mapa,
// para no necesitar dos mapas separados.
const pickingPoint = ref('origin');

function pickPoint({ lat, lng }) {
    if (pickingPoint.value === 'origin') {
        form.origin_lat = lat;
        form.origin_lng = lng;
    } else {
        form.destination_lat = lat;
        form.destination_lng = lng;
    }
}

// Mismo efecto que marcar en el mapa, solo que el punto viene resuelto por
// una sugerencia de Google Places (decisión explícita del usuario: Google
// solo para autocompletar direcciones, ver Components/AddressAutocomplete.vue).
function pickOriginFromAddress({ lat, lng }) {
    form.origin_lat = lat;
    form.origin_lng = lng;
}

function pickDestinationFromAddress({ lat, lng }) {
    form.destination_lat = lat;
    form.destination_lng = lng;
}

const mapMarkers = computed(() => {
    const markers = [];
    if (form.origin_lat != null) markers.push({ id: 'origin', lat: form.origin_lat, lng: form.origin_lng, label: 'Origen' });
    if (form.destination_lat != null) markers.push({ id: 'destination', lat: form.destination_lat, lng: form.destination_lng, label: 'Destino' });
    return markers;
});

function addCondition() {
    form.conditions.push('');
}

function removeCondition(index) {
    form.conditions.splice(index, 1);
}

const canSubmit = computed(() =>
    form.origin_lat != null && form.destination_lat != null && form.days_of_week.length > 0
);

function submit() {
    form.post(route('express-routes.store'), {
        onSuccess: () => {
            form.reset();
            showCreateForm.value = false;
        },
    });
}
</script>

<template>
    <Head title="Mis Expresos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between flex-wrap gap-2">
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Mis Expresos</h2>
                <div class="flex items-center gap-3 text-sm">
                    <Link :href="route('express-companions.discover')" class="text-arka-primary hover:text-arka-primary-bright">
                        Buscar Expresos para compartir &rarr;
                    </Link>
                    <Link :href="route('express-routes.available')" class="text-arka-primary hover:text-arka-primary-bright">
                        Postularme a uno &rarr;
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Una ruta fija y recurrente para gente con horario de entrada/salida fijo (sección 4): la publicás
                    una sola vez, un conductor se postula, y el sistema genera la solicitud de carrera automáticamente
                    cada día que corresponda.
                </p>

                <!-- Pedido explícito del usuario ("no sé a quién le aparece un
                     Expreso"): quién lo ve del otro lado no es obvio a
                     simple vista — aclararlo acá evita el caso real que
                     pasó (probar con conductores que nunca lo iban a ver
                     porque no eran miembros de ninguna flota tuya). -->
                <p class="text-xs text-arka-text-muted bg-arka-base/60 p-3 rounded-arka">
                    ℹ️ Solo lo van a ver los conductores que ya son <strong>miembros activos de alguna de sus
                    flotas</strong> — no es un aviso a todos los conductores de la plataforma. Si el conductor del que
                    espera postulaciones todavía no aceptó su invitación, vaya a "Mi Flota" primero.
                </p>

                <ul v-if="routes.length" class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="r in routes" :key="r.id" class="p-4 sm:p-6">
                        <Link :href="route('express-routes.show', r.id)" class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-arka-text font-medium hover:text-arka-primary-bright">{{ r.name }}</p>
                                <p class="text-sm text-arka-text-muted">
                                    {{ STATUS_LABELS[r.status] }}
                                    <span v-if="r.assigned_driver"> · {{ r.assigned_driver.name }}</span>
                                    <span v-if="r.pending_applications_count"> · {{ r.pending_applications_count }} postulación(es) nueva(s) </span>
                                    <span v-if="r.share_enabled" class="text-arka-primary-bright"> · abierto a compartir</span>
                                </p>
                            </div>
                            <span class="text-sm text-arka-text-muted shrink-0">${{ r.offered_price }}/carrera</span>
                        </Link>
                    </li>
                </ul>
                <p v-else class="text-sm text-arka-text-muted">Todavía no publicaste ningún Expreso.</p>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div v-if="!showCreateForm">
                        <PrimaryButton @click="showCreateForm = true">Publicar Expreso</PrimaryButton>
                    </div>

                    <form v-else @submit.prevent="submit" class="space-y-4">
                        <div>
                            <InputLabel value="Nombre (para identificarlo entre varios)" />
                            <TextInput class="mt-1 block w-full" v-model="form.name" placeholder="Ej: Turno mañana - Oficina Norte" required />
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <InputLabel value="Origen y destino" />
                                <div class="flex gap-2 text-xs">
                                    <button
                                        type="button"
                                        class="px-2 py-1 rounded-arka"
                                        :class="pickingPoint === 'origin' ? 'bg-arka-primary text-arka-base' : 'bg-arka-base text-arka-text-muted'"
                                        @click="pickingPoint = 'origin'"
                                    >
                                        Marcar origen
                                    </button>
                                    <button
                                        type="button"
                                        class="px-2 py-1 rounded-arka"
                                        :class="pickingPoint === 'destination' ? 'bg-arka-primary text-arka-base' : 'bg-arka-base text-arka-text-muted'"
                                        @click="pickingPoint = 'destination'"
                                    >
                                        Marcar destino
                                    </button>
                                </div>
                            </div>
                            <FleetMap :markers="mapMarkers" :clickable="true" @map-click="pickPoint" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Referencia del origen (opcional)" />
                                <AddressAutocomplete
                                    class="mt-1"
                                    v-model="form.origin_address"
                                    @place-selected="pickOriginFromAddress"
                                />
                            </div>
                            <div>
                                <InputLabel value="Referencia del destino (opcional)" />
                                <AddressAutocomplete
                                    class="mt-1"
                                    v-model="form.destination_address"
                                    @place-selected="pickDestinationFromAddress"
                                />
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Días de la semana" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label
                                    v-for="day in DAYS"
                                    :key="day.value"
                                    class="px-3 py-1.5 rounded-arka border cursor-pointer text-sm"
                                    :class="form.days_of_week.includes(day.value) ? 'border-arka-primary bg-arka-primary/10 text-arka-text' : 'border-arka-text-muted/20 text-arka-text-muted'"
                                >
                                    <input type="checkbox" :value="day.value" v-model="form.days_of_week" class="hidden" />
                                    {{ day.label }}
                                </label>
                            </div>
                            <InputError class="mt-1" :message="form.errors.days_of_week" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Hora de salida" />
                                <TextInput type="time" class="mt-1 block w-full" v-model="form.departure_time" required />
                            </div>
                            <div>
                                <InputLabel value="Precio por carrera (USD)" />
                                <TextInput type="number" step="0.01" min="0.01" class="mt-1 block w-full" v-model="form.offered_price" required />
                                <InputError class="mt-1" :message="form.errors.offered_price" />
                            </div>
                        </div>

                        <!-- Pedido explícito del usuario: hoy un conductor no le
                             conviene hacer un Expreso porque va vacío por una sola
                             persona — abrirlo a compartir reparte el costo entre más
                             gente con ruta parecida. -->
                        <div class="p-3 rounded-arka border border-arka-text-muted/20 space-y-2">
                            <label class="flex items-center">
                                <Checkbox v-model:checked="form.share_enabled" />
                                <span class="ms-2 text-sm text-arka-text">
                                    Abrir este Expreso a que otras personas de ruta parecida se sumen
                                </span>
                            </label>
                            <p class="text-xs text-arka-text-muted">
                                El precio total pactado con el conductor se reparte entre usted y quienes se sumen —
                                a más gente, menos le toca pagar a cada uno.
                            </p>
                            <div v-if="form.share_enabled">
                                <InputLabel value="Cupo de acompañantes (además de usted)" />
                                <TextInput type="number" min="1" max="6" class="mt-1 block w-32" v-model.number="form.max_companions" />
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Condiciones pactadas (opcional)" />
                            <div v-for="(condition, index) in form.conditions" :key="index" class="flex gap-2 mt-2">
                                <TextInput class="block w-full" v-model="form.conditions[index]" placeholder="Ej: Aire acondicionado" />
                                <SecondaryButton type="button" @click="removeCondition(index)">Quitar</SecondaryButton>
                            </div>
                            <button type="button" class="mt-2 text-sm text-arka-primary hover:text-arka-primary-bright" @click="addCondition">
                                + Agregar condición
                            </button>
                        </div>

                        <div class="flex gap-2">
                            <PrimaryButton :disabled="!canSubmit || form.processing">Publicar</PrimaryButton>
                            <SecondaryButton type="button" @click="showCreateForm = false">Cancelar</SecondaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
