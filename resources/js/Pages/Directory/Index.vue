<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import RatingStars from '@/Components/RatingStars.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { tierColorClass, tierLabel } from '@/Utils/tierBadge';
import { etaMinutes } from '@/Utils/eta';

const props = defineProps({
    drivers: { type: Object, required: true },
    targetFleetId: { type: Number, required: true },
    filters: { type: Object, default: () => ({}) },
});
const typeFilter = ref(props.filters.type ?? 'all');

function applyTypeFilter() {
    router.get(route('directory.index'), typeFilter.value === 'all' ? {} : { type: typeFilter.value }, { preserveState: true, preserveScroll: true });
}

// Pedido explícito del usuario: "Pedir una carrera" es una acción del lado
// cliente.
const isClient = usePage().props.auth.isClient;

// Apenas se abre la pantalla, si el navegador comparte la ubicación, se
// vuelve a pedir la lista ordenada por cercanía en vez de por calificación
// (sección 3.4: "filtrable por cercanía y calificación").
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition((position) => {
        router.reload({
            data: { lat: position.coords.latitude, lng: position.coords.longitude },
            preserveScroll: true,
        });
    });
}

function invite(driver) {
    router.post(
        route('fleet.invitations.store', props.targetFleetId),
        { driver_user_id: driver.user_id },
        {
            preserveScroll: true,
            onSuccess: () => {
                driver.status = 'pending';
            },
        }
    );
}
</script>

<template>
    <Head title="Directorio de conductores" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Directorio de conductores</h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Conductores con visibilidad pública, para cuando nadie de su flota está disponible (sección
                    3.4). Si la experiencia es buena, invítelo a su flota de confianza.
                </p>

                <div class="rounded-arka bg-arka-card p-4 shadow">
                    <label for="driver_type_filter" class="text-xs font-medium uppercase tracking-wide text-arka-text-muted">Tipo de conductor</label>
                    <select id="driver_type_filter" v-model="typeFilter" class="mt-2 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-sm text-arka-text" @change="applyTypeFilter">
                        <option value="all">Todos los conductores verificados</option>
                        <option value="independent">Independientes verificados</option>
                        <option value="public_transport">Transporte público verificado</option>
                        <option value="cooperative">Conductores de cooperativas</option>
                    </select>
                </div>

                <!-- Estado vacío con CTA claro, no una pantalla en blanco (sección 9.10) -->
                <div v-if="!drivers.data.length" class="p-6 bg-arka-card shadow rounded-arka text-center">
                    <p class="text-arka-text-muted">Todavía no hay conductores públicos para mostrar.</p>
                </div>

                <div v-else class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <div v-for="driver in drivers.data" :key="driver.user_id" class="p-4 sm:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <UserAvatar :user="driver" size-class="h-12 w-12 text-sm shrink-0" />
                            <div class="flex-1">
                                <Link
                                    :href="route('profiles.show', driver.user_id)"
                                    class="text-arka-text font-medium hover:text-arka-primary-bright"
                                >
                                    {{ driver.name }}
                                </Link>
                                <span v-if="driver.is_verified" class="ms-1 text-xs text-arka-primary-bright" title="Conductor verificado">✓</span>
                                <!-- Medalla por puntos (pedido explícito del usuario): solo
                                     llega hasta acá un conductor Oro o Diamante — reforzarlo
                                     visualmente, es lo que lo hizo elegible para aparecer. -->
                                <span
                                    v-if="driver.tier"
                                    class="ms-1.5 px-1.5 py-0.5 rounded text-[10px] font-medium"
                                    :class="tierColorClass(driver.tier.color_key)"
                                >
                                    {{ tierLabel(driver.tier) }}
                                </span>
                                <div class="mt-1">
                                    <RatingStars
                                        :rating="driver.average_rating ?? 0"
                                        :count="driver.review_count"
                                        readonly
                                    />
                                </div>
                                <p v-if="driver.trust_label" class="mt-1 text-xs font-medium text-arka-primary-bright">✓ {{ driver.trust_label }}</p>
                                <p v-if="driver.cooperative" class="mt-1 text-xs text-arka-text-muted">
                                    Afiliado a <Link :href="route('cooperatives.show', driver.cooperative.id)" class="text-arka-primary hover:underline">{{ driver.cooperative.name }}</Link>
                                </p>
                                <p class="mt-1 text-xs text-arka-text-muted">{{ driver.clients_count }} cliente{{ driver.clients_count === 1 ? '' : 's' }} lo tienen agregado</p>
                                <p class="mt-1 text-sm text-arka-text-muted">
                                    ${{ driver.rate_per_km }}/km
                                    <span v-if="driver.vehicle_type"> · {{ driver.vehicle_type }}</span>
                                    <!-- Pedido explícito del usuario ("los km cercano
                                         manejemos minutos mejor para la distancia"):
                                         minutos estimados en vez del km exacto hasta
                                         un conductor puntual (Utils/eta.js, mismo
                                         criterio que Ride/Request.vue). -->
                                    <span v-if="driver.distance_km != null"> · {{ etaMinutes(driver.distance_km) }} min</span>
                                    <span v-if="!driver.is_available"> · no disponible ahora</span>
                                </p>
                            </div>

                            <div class="flex flex-col items-end gap-1.5 shrink-0">
                                <!-- Pedido explícito del usuario: elegir un conductor
                                     tiene que ofrecer pedirle una carrera directo a él. -->
                                <Link
                                    v-if="isClient"
                                    :href="route('ride-requests.create', { flota: targetFleetId, conductor: driver.user_id })"
                                    class="text-xs text-arka-primary hover:text-arka-primary-bright font-medium"
                                >
                                    Pedir carrera
                                </Link>
                                <PrimaryButton v-if="driver.status === 'not_invited'" @click="invite(driver)">
                                    Invitar
                                </PrimaryButton>
                                <span v-else-if="driver.status === 'pending'" class="text-sm text-arka-lime">
                                    Invitación enviada
                                </span>
                                <span v-else class="text-sm text-arka-text-muted">Ya está en su flota</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Paginado simple: nunca se carga todo de una vez (sección 9.7) -->
                <div v-if="drivers.prev_page_url || drivers.next_page_url" class="flex justify-between">
                    <Link
                        v-if="drivers.prev_page_url"
                        :href="drivers.prev_page_url"
                        class="text-sm text-arka-primary hover:text-arka-primary-bright"
                    >
                        &larr; Anterior
                    </Link>
                    <span v-else></span>

                    <Link
                        v-if="drivers.next_page_url"
                        :href="drivers.next_page_url"
                        class="text-sm text-arka-primary hover:text-arka-primary-bright"
                    >
                        Siguiente &rarr;
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
