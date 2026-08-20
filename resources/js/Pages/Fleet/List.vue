<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import FleetRoster from '@/Components/FleetRoster.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

// Pedido explícito del usuario ("que no vaya un paso más... que ahí ya salga
// su flota, y los botones de agregar los acomodes por ahí"): antes cada
// flota era solo una tarjeta resumen que llevaba a Fleet/Show.vue para ver
// conductores e invitar — ahora `fleets` trae el roster completo de cada una
// (ver FleetController::index()/fleetDetails()), y se arma acá mismo con
// Components/FleetRoster.vue, sin esa navegación extra. En el plan Gratis
// (el caso más común) esto es una sola flota, así que la pantalla completa
// queda de una sin ningún clic de por medio.
const props = defineProps({
    fleets: { type: Array, required: true },
    // null = sin límite de flotas (plan Multi-flota con cupo personalizado).
    maxFleets: { type: Number, default: null },
    // null = sin límite de conductores por flota.
    maxDriversPerFleet: { type: Number, default: null },
    planCode: { type: String, required: true },
    planName: { type: String, required: true },
    cooperatives: { type: Array, default: () => [] },
});

const atLimit = props.maxFleets !== null && props.fleets.length >= props.maxFleets;
const showCreateForm = ref(false);
const cooperativeSearch = ref('');
const filteredCooperatives = computed(() => {
    const term = cooperativeSearch.value.trim().toLocaleLowerCase('es');
    return props.cooperatives.filter((cooperative) => !term || cooperative.name.toLocaleLowerCase('es').includes(term));
});
const attachedCooperatives = computed(() => props.cooperatives.filter((cooperative) => cooperative.is_attached));

const form = useForm({ name: '' });

const submit = () => {
    form.post(route('fleet.store'), {
        onSuccess: () => {
            form.reset();
            showCreateForm.value = false;
        },
    });
};

// Pedido explícito del usuario: que el botón "Crear flota" valide el cupo
// del plan y, si no alcanza, lleve directo a subir de plan en vez de
// quedarse deshabilitado con solo un texto de ayuda al costado (fácil de
// pasar por alto).
function handleCreateClick() {
    if (atLimit) {
        router.visit(route('client.plan.edit'));
        return;
    }
    showCreateForm.value = true;
}

function toggleCooperative(cooperative) {
    if (cooperative.is_attached) {
        router.delete(route('cooperatives.detach', cooperative.id), { preserveScroll: true });
        return;
    }
    router.post(route('cooperatives.attach', cooperative.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Mis flotas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Mis flotas</h2>
                <span class="text-sm text-arka-text-muted">
                    Plan {{ planName }} · {{ fleets.length }} de {{ maxFleets ?? '∞' }} flotas
                </span>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                <!-- Una de estas por flota — en el plan Gratis (el caso más común)
                     es una sola, así que el buscador para invitar y el roster de
                     conductores quedan de una en esta misma pantalla, sin tocar
                     nada más. -->
                <div v-for="fleetData in fleets" :key="fleetData.fleet.id" class="space-y-3">
                    <h3 v-if="fleets.length > 1" class="text-lg font-medium text-arka-text">{{ fleetData.fleet.name }}</h3>
                    <FleetRoster :fleet="fleetData.fleet" :max-drivers-per-fleet="maxDriversPerFleet" :member-stats="fleetData.memberStats" />
                </div>

                <section class="rounded-arka bg-arka-card p-4 shadow sm:p-6">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-arka-primary">Cooperativas de confianza</p>
                            <h3 class="mt-1 text-lg font-semibold text-arka-text">Cooperativas en su red</h3>
                            <p class="mt-1 text-sm text-arka-text-muted">Busque por nombre y agréguela para poder solicitarle carreras.</p>
                        </div>
                        <span class="rounded-full bg-arka-primary/10 px-3 py-1 text-xs font-semibold text-arka-primary">{{ attachedCooperatives.length }} agregadas</span>
                    </div>

                    <input v-model="cooperativeSearch" type="search" placeholder="Buscar cooperativa por nombre" class="mt-4 w-full rounded-arka border-arka-text-muted/20 bg-arka-base px-4 py-3 text-sm text-arka-text placeholder:text-arka-text-muted focus:border-arka-primary focus:ring-arka-primary" />

                    <p v-if="!cooperatives.length" class="mt-4 rounded-arka border border-arka-text-muted/10 p-4 text-sm text-arka-text-muted">No existen cooperativas aprobadas disponibles en este momento.</p>
                    <p v-else-if="!filteredCooperatives.length" class="mt-4 text-sm text-arka-text-muted">No encontramos una cooperativa con ese nombre.</p>
                    <div v-else class="mt-4 grid gap-3 sm:grid-cols-2">
                        <article v-for="cooperative in filteredCooperatives" :key="cooperative.id" class="flex items-center gap-3 rounded-arka border p-3" :class="cooperative.is_attached ? 'border-arka-primary/40 bg-arka-primary/5' : 'border-arka-text-muted/15'">
                            <img v-if="cooperative.logo_url" :src="cooperative.logo_url" :alt="`Logo de ${cooperative.name}`" class="h-11 w-11 rounded-xl bg-white object-contain p-1" />
                            <div v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-arka-primary/15 font-bold text-arka-primary">{{ cooperative.name.charAt(0) }}</div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-arka-text">{{ cooperative.name }}</p>
                                <p class="truncate text-xs text-arka-text-muted">{{ cooperative.main_address || 'Parada por confirmar' }}</p>
                                <p class="mt-0.5 text-xs text-arka-primary">{{ cooperative.active_drivers }} conductores activos</p>
                            </div>
                            <button type="button" class="shrink-0 rounded-full px-3 py-2 text-xs font-semibold" :class="cooperative.is_attached ? 'border border-arka-text-muted/20 text-arka-text-muted' : 'bg-arka-primary text-arka-base'" @click="toggleCooperative(cooperative)">{{ cooperative.is_attached ? 'Retirar' : 'Agregar' }}</button>
                        </article>
                    </div>
                </section>

                <!-- Crear otra flota: solo tiene sentido desde el plan Multi-flota
                     (sección 7.3). Pedido explícito del usuario: en vez de un botón
                     deshabilitado con solo un texto de ayuda al costado (fácil de
                     pasar por alto), el botón queda siempre activo — si no alcanza el
                     plan, lleva directo a "Mi plan" a subirlo. -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div v-if="!showCreateForm" class="flex items-center justify-between gap-4">
                        <p class="text-sm text-arka-text-muted">
                            <span v-if="atLimit">
                                Llegó al límite de flotas de su plan {{ planName }} — toque para ver los planes con más cupo.
                            </span>
                            <span v-else>¿Necesita separar sus conductores en otra flota?</span>
                        </p>
                        <PrimaryButton class="shrink-0" @click="handleCreateClick">
                            {{ atLimit ? 'Subir de plan' : 'Crear otra flota' }}
                        </PrimaryButton>
                    </div>

                    <form v-else @submit.prevent="submit" class="flex items-end gap-3">
                        <div class="flex-1">
                            <InputLabel for="name" value="Nombre de la nueva flota" />
                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.name"
                                placeholder="Ej: Turno noche"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>
                        <PrimaryButton :disabled="form.processing">Crear</PrimaryButton>
                        <SecondaryButton type="button" @click="showCreateForm = false">Cancelar</SecondaryButton>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
