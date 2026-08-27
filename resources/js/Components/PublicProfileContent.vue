<script setup>
import UserAvatar from '@/Components/UserAvatar.vue';
import { Link } from '@inertiajs/vue3';

// Cuerpo del perfil público, aparte del layout que lo envuelve (pedido
// explícito del usuario: "compartir mi perfil" ahora es accesible sin
// sesión, para quien escanea el QR o abre el link sin tener cuenta
// todavía) — se reutiliza tal cual desde Profile/Show.vue, tanto para un
// visitante con sesión (AuthenticatedLayout de siempre) como sin ella
// (presentación mínima propia, sin nav).
defineProps({
    profileUser: { type: Object, required: true },
    averageRating: { type: Number, required: true },
    reviewCount: { type: Number, required: true },
    reviews: { type: Object, required: true },
    isClient: { type: Boolean, required: true },
    isDriver: { type: Boolean, required: true },
    canRequestRide: { type: Boolean, required: true },
    embedded: { type: Boolean, default: false },
    showSummaryBadges: { type: Boolean, default: true },
    showSummaryRating: { type: Boolean, default: true },
    // Pedido explícito del usuario ("mejoremos la privacidad de los
    // conductores"): true cuando el conductor apagó su perfil individual —
    // se oculta vehículo/tarifa/reseñas y se avisa en su lugar.
    profilePrivate: { type: Boolean, default: false },
});
</script>

<template>
    <div
        v-if="showSummaryBadges || isDriver"
        :class="embedded ? 'pt-5' : 'p-4 sm:p-6 bg-arka-card shadow rounded-arka'"
    >
        <!-- Marca de rol(es) + calificación compacta (sección 3.1 y 3.6):
             de un vistazo, qué es esta persona y qué tan bien la calificaron. -->
        <div
            v-if="showSummaryBadges"
            class="flex flex-wrap items-center gap-2 mb-3"
            :class="embedded ? 'justify-center' : ''"
        >
            <span v-if="isClient" class="px-3 py-1 rounded-full text-xs font-medium bg-arka-primary/15 text-arka-primary-bright">
                Cliente
            </span>
            <span v-if="isDriver" class="px-3 py-1 rounded-full text-xs font-medium bg-arka-primary/15 text-arka-primary-bright">
                Conductor
            </span>
            <Link
                v-if="isDriver && profileUser.driver_profile?.cooperative"
                :href="route('cooperatives.show', profileUser.driver_profile.cooperative.id)"
                class="inline-flex items-center gap-1.5 rounded-full border border-arka-primary/25 bg-arka-primary/10 px-3 py-1 text-xs font-semibold text-arka-primary-bright hover:border-arka-primary/60"
            >
                <span aria-hidden="true">◉</span>
                Conductor de {{ profileUser.driver_profile.cooperative.name }}
            </Link>
            <span
                v-if="reviewCount > 0 && showSummaryRating"
                class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-arka-lime/15 text-arka-lime"
            >
                <span class="text-sm leading-none">★</span> {{ averageRating.toFixed(1) }}
            </span>
        </div>

        <!-- Pedido explícito del usuario: quien no sea el propio conductor
             ni un admin ve esto en vez de vehículo/tarifa/reseñas. -->
        <div v-if="isDriver && profilePrivate" class="mt-4 flex items-center gap-2 rounded-arka bg-arka-base/40 p-3 text-sm text-arka-text-muted">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <rect x="5" y="11" width="14" height="9" rx="2" />
                <path stroke-linecap="round" d="M8 11V8a4 4 0 0 1 8 0v3" />
            </svg>
            Este conductor mantiene los detalles de su perfil en privado.
        </div>

        <div v-else-if="profileUser.driver_profile" class="mt-4 text-sm text-arka-text-muted space-y-1">
            <!-- Confidencialidad (pedido explícito del usuario): acá ya no va
                 la foto del vehículo (solo el propio conductor y un admin la
                 ven) ni la placa completa — el tipo de vehículo es el dato
                 que sí se muestra tal cual (ver DriverProfile::maskedPlate()). -->
            <p>
                {{ profileUser.driver_profile.vehicle_make }} {{ profileUser.driver_profile.vehicle_model }}
                <span v-if="profileUser.driver_profile.vehicle_type">
                    · {{ profileUser.driver_profile.vehicle_type }}
                </span>
                <span v-if="profileUser.driver_profile.vehicle_plate">
                    · Placa {{ profileUser.driver_profile.vehicle_plate }}
                </span>
            </p>
            <p>${{ profileUser.driver_profile.rate_per_km }}/km</p>
            <p>
                Acepta:
                <span v-if="profileUser.driver_profile.accepts_cash">efectivo</span>
                <span v-if="profileUser.driver_profile.accepts_cash && profileUser.driver_profile.accepts_transfer">
                    y
                </span>
                <span v-if="profileUser.driver_profile.accepts_transfer">transferencia</span>
            </p>
            <p v-if="profileUser.driver_profile.verification_status === 'approved'" class="text-arka-primary-bright">
                ✓ {{ profileUser.driver_profile.trust_label || 'Conductor verificado' }}
            </p>
            <p>{{ profileUser.driver_profile.clients_count }} cliente{{ profileUser.driver_profile.clients_count === 1 ? '' : 's' }} lo tienen agregado en su flota</p>

            <!-- Pedido explícito del usuario: elegir un conductor (acá,
                 abriendo su perfil) tiene que ofrecer pedirle una carrera
                 directo a él. Solo tiene sentido si quien mira es cliente
                 con sesión iniciada. -->
            <Link
                v-if="canRequestRide"
                :href="route('ride-requests.create', { conductor: profileUser.id })"
                class="inline-block mt-2 px-4 py-2 rounded-arka bg-arka-primary text-arka-base text-xs font-semibold uppercase tracking-widest hover:opacity-90"
            >
                Pedir carrera
            </Link>
        </div>
    </div>

    <div
        v-if="!(isDriver && profilePrivate) && reviews.data.length"
        :class="embedded
            ? 'mt-6 border-t border-arka-text-muted/10 pt-5'
            : 'p-4 sm:p-6 bg-arka-card shadow rounded-arka mt-6'"
    >
        <h3 class="text-lg font-medium text-arka-text mb-4">Comentarios</h3>

        <ul class="divide-y divide-arka-text-muted/10">
            <li v-for="review in reviews.data" :key="review.id" class="py-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <UserAvatar :user="review.reviewer" size-class="h-7 w-7 text-xs shrink-0" />
                        <span class="text-arka-text font-medium">{{ review.reviewer.name }}</span>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-arka-lime/10 px-2 py-1 text-xs font-semibold text-arka-lime">
                        <span aria-hidden="true">★</span>
                        {{ Number(review.rating).toFixed(1) }}
                    </span>
                </div>
                <p v-if="review.comment" class="mt-1 text-sm text-arka-text-muted italic">
                    "{{ review.comment }}"
                </p>
            </li>
        </ul>

        <div v-if="reviews.prev_page_url || reviews.next_page_url" class="flex justify-between mt-4">
            <Link
                v-if="reviews.prev_page_url"
                :href="reviews.prev_page_url"
                class="text-sm text-arka-primary hover:text-arka-primary-bright"
            >
                &larr; Anterior
            </Link>
            <span v-else></span>

            <Link
                v-if="reviews.next_page_url"
                :href="reviews.next_page_url"
                class="text-sm text-arka-primary hover:text-arka-primary-bright"
            >
                Siguiente &rarr;
            </Link>
        </div>
    </div>
</template>
