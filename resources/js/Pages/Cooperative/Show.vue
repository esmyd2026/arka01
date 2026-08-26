<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({ cooperative: Object, reputation: Object, drivers: Array, fleetVisible: Boolean, reviews: Array, isAttached: Boolean });
const isClient = usePage().props.auth?.isClient ?? false;
const date = (value) => new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium' }).format(new Date(value));
function toggle() {
    if (props.isAttached) router.delete(route('cooperatives.detach', props.cooperative.id));
    else router.post(route('cooperatives.attach', props.cooperative.id));
}
</script>

<template>
    <!-- Bug real reportado por el usuario (con captura: "tienes full espacio
         y lo tienes todo alli agrupado") — GuestLayout capaba el ancho a
         448px (pensado para un formulario de login) sin importar qué tan
         ancha fuera la pantalla, así que esta página de contenido rico
         (estadísticas, conductores, reseñas) quedaba apretada en esa
         columna angosta en cualquier pantalla ≥640px. Ancho completo, sin el
         panel de marca (compite por espacio con el contenido) ni la tarjeta
         extra que envuelve el slot (esta página ya arma sus propias
         tarjetas por sección). -->
    <GuestLayout max-width-class="sm:max-w-5xl" :show-branding-panel="false" :wrap-content="false">
        <Head :title="cooperative.name || 'Cooperativa'" />
        <div class="w-full max-w-5xl space-y-5">
            <!-- Pedido explícito del usuario (con captura de un celular real:
                 "esta muy mal, tienes full espacio y lo tienes todo alli
                 agrupado") — antes el logo, el nombre, la calificación y el
                 botón de agregar competían por la misma fila apretada en
                 mobile. Ahora en mobile es una columna centrada con su
                 propio espacio (logo → insignia → nombre → calificación →
                 botón), y recién en escritorio (sm:) pasa a fila. -->
            <section class="overflow-hidden rounded-3xl border border-arka-primary/15 bg-arka-card shadow-2xl">
                <div class="bg-gradient-to-br from-arka-primary/25 via-arka-card to-arka-lime/10 p-6 sm:p-9">
                    <div class="flex flex-col items-center text-center gap-4 sm:flex-row sm:items-center sm:text-start sm:gap-6">
                        <img
                            v-if="cooperative.logo_url"
                            :src="cooperative.logo_url"
                            class="h-24 w-24 shrink-0 rounded-2xl bg-white object-contain p-2"
                            alt="Logo"
                        />
                        <div v-else class="grid h-24 w-24 shrink-0 place-items-center rounded-2xl bg-arka-primary/15 text-4xl font-bold text-arka-primary">
                            {{ cooperative.name?.charAt(0) }}
                        </div>

                        <div class="min-w-0 flex-1 space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-arka-primary-bright">✓ Cooperativa verificada</p>
                            <h1 class="text-2xl font-bold text-arka-text sm:text-3xl">{{ cooperative.name }}</h1>
                            <!-- Bug real reportado por el usuario (con
                                 captura: un "·" solo, flotando sin nada a
                                 los lados) — con ciudad y provincia vacías
                                 (dato opcional, no siempre cargado) el
                                 separador se mostraba igual, huérfano. -->
                            <p v-if="cooperative.city?.name || cooperative.province" class="text-sm text-arka-text-muted">
                                <template v-if="cooperative.city?.name && cooperative.province">{{ cooperative.city.name }} · {{ cooperative.province }}</template>
                                <template v-else>{{ cooperative.city?.name || cooperative.province }}</template>
                            </p>
                            <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                                <span class="rounded-full bg-arka-warning/10 px-3 py-1 text-sm font-bold text-arka-warning">
                                    ★ {{ reputation.average_rating || 'Nueva' }}
                                </span>
                                <span class="text-sm text-arka-text-muted">{{ reputation.review_count }} opiniones verificadas</span>
                            </div>
                        </div>

                        <div v-if="isClient" class="w-full shrink-0 sm:w-auto">
                            <SecondaryButton v-if="isAttached" class="w-full justify-center sm:w-auto" @click="toggle">
                                Retirar de mi red
                            </SecondaryButton>
                            <PrimaryButton v-else class="w-full justify-center sm:w-auto" @click="toggle">
                                Agregar a mi red
                            </PrimaryButton>
                        </div>
                    </div>
                </div>

                <!-- Antes eran celdas pegadas entre sí con una línea de 1px
                     como única separación ("todo agrupado") — ahora cada
                     estadística es su propia tarjeta, con aire real entre
                     ellas, 2 por fila en mobile (nunca las 5 apretadas). -->
                <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-5 sm:gap-3 sm:p-6">
                    <div class="rounded-2xl bg-arka-base/40 p-4 text-center">
                        <p class="text-2xl font-bold text-arka-primary">{{ reputation.client_count }}</p>
                        <p class="mt-1 text-xs text-arka-text-muted">Clientes vinculados</p>
                    </div>
                    <div class="rounded-2xl bg-arka-base/40 p-4 text-center">
                        <p class="text-2xl font-bold text-arka-text">{{ reputation.completed_rides }}</p>
                        <p class="mt-1 text-xs text-arka-text-muted">Carreras completadas</p>
                    </div>
                    <div class="rounded-2xl bg-arka-base/40 p-4 text-center">
                        <!-- reputation.driver_count (no drivers.length): sigue siendo
                             la cantidad real aunque la lista esté oculta más abajo. -->
                        <p class="text-2xl font-bold text-arka-text">{{ reputation.driver_count }}</p>
                        <p class="mt-1 text-xs text-arka-text-muted">Conductores activos</p>
                    </div>
                    <div class="rounded-2xl bg-arka-base/40 p-4 text-center">
                        <p class="text-2xl font-bold text-arka-warning">{{ reputation.average_rating || '—' }}</p>
                        <p class="mt-1 text-xs text-arka-text-muted">Calificación promedio</p>
                    </div>
                    <div class="col-span-2 rounded-2xl bg-arka-base/40 p-4 text-center sm:col-span-1">
                        <p class="text-2xl font-bold text-arka-text">{{ reputation.cancelled_rides }}</p>
                        <p class="mt-1 text-xs text-arka-text-muted">Canceladas</p>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 rounded-2xl bg-arka-card p-5 sm:grid-cols-2 sm:p-6">
                <div class="rounded-2xl bg-arka-base/40 p-4">
                    <p class="text-xs uppercase tracking-wider text-arka-primary">Cobertura</p>
                    <p class="mt-2 text-sm text-arka-text">{{ cooperative.geographic_coverage || 'Cobertura no especificada' }}</p>
                </div>
                <div class="rounded-2xl bg-arka-base/40 p-4">
                    <p class="text-xs uppercase tracking-wider text-arka-primary">Horario</p>
                    <p class="mt-2 text-sm text-arka-text">{{ cooperative.operating_hours || 'Horario no especificado' }}</p>
                </div>
            </section>

            <section class="rounded-2xl bg-arka-card p-5 sm:p-6">
                <p class="text-xs uppercase tracking-widest text-arka-primary">Equipo</p>
                <h2 class="mt-1 text-xl font-bold text-arka-text">Conductores de la cooperativa</h2>
                <p class="mt-1 text-sm text-arka-text-muted">Sus carreras y calificaciones forman la reputación de la organización.</p>

                <!-- Pedido explícito del usuario: "que salga solo las
                     cantidades y los conductores como bloqueados para ver la
                     flota" — sin nombres, fotos ni links a perfiles
                     individuales, solo la cantidad (ya mostrada arriba en la
                     tarjeta de estadísticas). -->
                <div v-if="!fleetVisible" class="mt-5 flex items-center gap-3 rounded-2xl border border-arka-text-muted/10 bg-arka-base/40 p-5">
                    <div class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-arka-text-muted/10 text-arka-text-muted">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="5" y="11" width="14" height="9" rx="2" />
                            <path stroke-linecap="round" d="M8 11V8a4 4 0 0 1 8 0v3" />
                        </svg>
                    </div>
                    <p class="text-sm text-arka-text-muted">
                        Esta cooperativa mantiene su flota en privado —
                        <strong class="text-arka-text">{{ reputation.driver_count }}</strong>
                        conductor{{ reputation.driver_count === 1 ? '' : 'es' }} activo{{ reputation.driver_count === 1 ? '' : 's' }}.
                    </p>
                </div>

                <div v-else class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="driver in drivers"
                        :key="driver.id"
                        :href="route('profiles.show', driver.id)"
                        class="group rounded-2xl border border-arka-text-muted/10 bg-arka-base/40 p-4 transition hover:border-arka-primary/40"
                    >
                        <div class="flex items-center gap-3">
                            <img v-if="driver.avatar_url" :src="driver.avatar_url" class="h-12 w-12 rounded-full object-cover" />
                            <div v-else class="grid h-12 w-12 place-items-center rounded-full bg-arka-primary/15 font-bold text-arka-primary">
                                {{ driver.name.charAt(0) }}
                            </div>
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-arka-text group-hover:text-arka-primary">{{ driver.name }}</p>
                                <p class="truncate text-xs text-arka-text-muted">{{ driver.vehicle || 'Unidad registrada' }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between text-xs">
                            <span class="text-arka-warning">
                                ★ {{ driver.average_rating || 'Nuevo' }} <span class="text-arka-text-muted">({{ driver.review_count }})</span>
                            </span>
                            <span class="font-semibold text-arka-primary">{{ driver.completed_rides }} carreras</span>
                        </div>
                    </Link>
                </div>
            </section>

            <section class="rounded-2xl bg-arka-card p-5 sm:p-6">
                <p class="text-xs uppercase tracking-widest text-arka-primary">Experiencias verificadas</p>
                <h2 class="mt-1 text-xl font-bold text-arka-text">Comentarios de clientes</h2>

                <p v-if="!reviews.length" class="mt-5 rounded-xl bg-arka-base/40 p-5 text-sm text-arka-text-muted">
                    Esta cooperativa todavía no tiene comentarios de carreras completadas.
                </p>
                <div v-else class="mt-5 grid gap-3 sm:grid-cols-2">
                    <article v-for="review in reviews" :key="review.id" class="rounded-2xl border border-arka-text-muted/10 bg-arka-base/40 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-semibold text-arka-text">{{ review.client }}</p>
                            <span class="text-sm font-bold text-arka-warning">★ {{ review.rating }}</span>
                        </div>
                        <p class="mt-3 text-sm leading-relaxed text-arka-text">“{{ review.comment }}”</p>
                        <p class="mt-3 text-xs text-arka-text-muted">Con {{ review.driver }} · {{ date(review.date) }}</p>
                        <p class="mt-1 truncate text-[11px] text-arka-text-muted/70">{{ review.route }}</p>
                    </article>
                </div>
            </section>
        </div>
    </GuestLayout>
</template>
