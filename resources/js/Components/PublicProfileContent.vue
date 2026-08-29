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
    trustIndex: { type: Object, default: null },
});

const componentWidth = (component) => `${Math.min(100, Math.round((component.points / component.maximum) * 100))}%`;
</script>

<template>
    <div
        v-if="showSummaryBadges || isDriver || trustIndex"
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
                :href="route('cooperatives.show', profileUser.driver_profile.cooperative.public_id)"
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

        <!-- El índice es una referencia explicable, no una etiqueta absoluta
             de seguridad. El resumen queda visible y el cálculo se despliega
             solo si la persona desea entenderlo. -->
        <section
            v-if="trustIndex"
            class="mt-4 rounded-2xl border border-arka-primary/20 bg-arka-base/35 p-4"
            aria-labelledby="trust-index-title"
        >
            <div class="flex items-center gap-4">
                <div
                    class="trust-score-ring shrink-0"
                    :style="{ '--trust-score': `${trustIndex.score * 3.6}deg` }"
                    :aria-label="`Índice de confianza ${trustIndex.score} de 100`"
                >
                    <div class="trust-score-ring__inner">
                        <strong class="text-xl leading-none text-arka-text">{{ trustIndex.score }}</strong>
                        <span class="text-[10px] text-arka-text-muted">/100</span>
                    </div>
                </div>

                <div class="min-w-0 flex-1">
                    <p id="trust-index-title" class="text-xs font-semibold uppercase tracking-[0.14em] text-arka-primary-bright">
                        Índice de confianza
                    </p>
                    <p class="mt-1 text-lg font-bold text-arka-text">{{ trustIndex.level }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-arka-text-muted">
                        {{ trustIndex.completed_rides }} carrera{{ trustIndex.completed_rides === 1 ? '' : 's' }} completada{{ trustIndex.completed_rides === 1 ? '' : 's' }}
                        · {{ trustIndex.reviews_count }} calificación{{ trustIndex.reviews_count === 1 ? '' : 'es' }}
                        <template v-if="trustIndex.network_connections !== null">
                            · {{ trustIndex.network_connections }} conexión{{ trustIndex.network_connections === 1 ? '' : 'es' }} en su red
                        </template>
                    </p>
                </div>
            </div>

            <details class="group mt-4 border-t border-arka-text-muted/10 pt-3">
                <summary class="flex cursor-pointer list-none items-center justify-between text-xs font-semibold text-arka-text-muted transition hover:text-arka-primary-bright">
                    Cómo se calcula
                    <span class="transition group-open:rotate-180" aria-hidden="true">⌄</span>
                </summary>

                <div class="mt-3 space-y-3">
                    <div v-for="component in trustIndex.components" :key="component.key">
                        <div class="mb-1 flex justify-between gap-3 text-xs">
                            <span class="text-arka-text-muted">{{ component.label }}</span>
                            <span class="font-medium text-arka-text">{{ component.points }}/{{ component.maximum }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-arka-text-muted/10">
                            <div class="h-full rounded-full bg-arka-primary" :style="{ width: componentWidth(component) }"></div>
                        </div>
                    </div>
                    <p class="text-[11px] leading-relaxed text-arka-text-muted">
                        Es una referencia basada en la actividad dentro de Arka01; no garantiza por sí sola la seguridad de una persona.
                    </p>
                </div>
            </details>
        </section>

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
                :href="route('ride-requests.create', { conductor: profileUser.public_id })"
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

<style scoped>
.trust-score-ring {
    display: grid;
    width: 4.5rem;
    height: 4.5rem;
    place-items: center;
    border-radius: 9999px;
    background: conic-gradient(rgb(52 211 153) var(--trust-score), rgb(148 163 184 / 0.14) 0deg);
}

.trust-score-ring__inner {
    display: flex;
    width: 3.75rem;
    height: 3.75rem;
    align-items: baseline;
    justify-content: center;
    border-radius: 9999px;
    background: rgb(10 25 19);
    padding-top: 1.25rem;
}
</style>
