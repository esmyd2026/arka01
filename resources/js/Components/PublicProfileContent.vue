<script setup>
import RatingStars from '@/Components/RatingStars.vue';
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
});
</script>

<template>
    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
        <!-- Marca de rol(es) + calificación compacta (sección 3.1 y 3.6):
             de un vistazo, qué es esta persona y qué tan bien la calificaron. -->
        <div class="flex flex-wrap items-center gap-2 mb-3">
            <span v-if="isClient" class="px-3 py-1 rounded-full text-xs font-medium bg-arka-primary/15 text-arka-primary-bright">
                Cliente
            </span>
            <span v-if="isDriver" class="px-3 py-1 rounded-full text-xs font-medium bg-arka-primary/15 text-arka-primary-bright">
                Conductor
            </span>
            <span
                v-if="reviewCount > 0"
                class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-arka-lime/15 text-arka-lime"
            >
                <span class="text-sm leading-none">★</span> {{ averageRating.toFixed(1) }}
            </span>
        </div>

        <RatingStars :rating="averageRating" :count="reviewCount" readonly />

        <div v-if="profileUser.driver_profile" class="mt-4 text-sm text-arka-text-muted space-y-1">
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
                ✓ Conductor verificado
            </p>

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

    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka mt-6">
        <h3 class="text-lg font-medium text-arka-text mb-4">Comentarios</h3>

        <p v-if="!reviews.data.length" class="text-sm text-arka-text-muted">
            Todavía no tiene comentarios.
        </p>

        <ul v-else class="divide-y divide-arka-text-muted/10">
            <li v-for="review in reviews.data" :key="review.id" class="py-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <UserAvatar :user="review.reviewer" size-class="h-7 w-7 text-xs shrink-0" />
                        <span class="text-arka-text font-medium">{{ review.reviewer.name }}</span>
                    </div>
                    <RatingStars :rating="review.rating" readonly />
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
