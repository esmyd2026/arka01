<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import BottomSheet from '@/Components/BottomSheet.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import TrustScoreBadge from '@/Components/TrustScoreBadge.vue';
import { dismissIncomingRideRequest, incomingRideRequestState } from '@/Utils/incomingRideRequest';

// Pedido explícito del usuario: la carrera entrante tiene que ocupar media
// pantalla (no un renglón más en una lista), con Aceptar/Descartar y también
// la X del modal para descartarla — se muestra sin importar en qué pantalla
// esté el conductor (montado una sola vez en AuthenticatedLayout.vue).
const current = computed(() => incomingRideRequestState.queue[0] ?? null);
const show = computed(() => current.value !== null);
const processing = ref(false);

// Bug real reportado por el usuario (con captura): decía "📅 Programada"
// pero nunca la hora — mismo formato que ya usa Ride/Index.vue.
function formatScheduledAt(iso) {
    return new Date(iso).toLocaleString('es-EC', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// Mismo patrón de contéo regresivo que Ride/Index.vue (despacho secuencial).
const nowFast = ref(Date.now());
let clock = null;
onMounted(() => {
    clock = setInterval(() => (nowFast.value = Date.now()), 1000);
});
onBeforeUnmount(() => clearInterval(clock));

const secondsLeft = computed(() => {
    if (!current.value?.current_offer_expires_at) return null;
    return Math.max(0, Math.round((new Date(current.value.current_offer_expires_at).getTime() - nowFast.value) / 1000));
});

// Bug real reportado (con capturas: dos conductores viendo la misma
// solicitud, uno con "0 seg." pegado para siempre, y encima podía tocar
// Aceptar y le tiraba un 403 porque ya era de otro conductor): en el momento
// justo en que se cumplen los 30 seg., el evento ".ride-request.cancelled"
// (AuthenticatedLayout.vue) normalmente ya la saca de acá — esto es solo un
// respaldo por si ese aviso tarda o se pierde, para que la tarjeta no quede
// interactuable para siempre esperando algo que ya no va a pasar.
watch(secondsLeft, (value) => {
    if (value !== 0 || !current.value) return;

    const id = current.value.id;
    setTimeout(() => {
        if (incomingRideRequestState.queue[0]?.id === id) {
            dismissIncomingRideRequest(id);
        }
    }, 3000);
});

function accept() {
    if (!current.value || processing.value) return;
    processing.value = true;
    const id = current.value.id;

    router.post(route('ride-requests.accept', id), {}, {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            dismissIncomingRideRequest(id);
        },
    });
}

function discard() {
    if (!current.value || processing.value) return;
    processing.value = true;
    const id = current.value.id;

    // Rechazar solo tiene sentido si la solicitud viene dirigida a mí (sección
    // 3.5) — acá SIEMPRE es así: este modal solo escucha el canal personal
    // (App.Models.User.{id}), y tanto una dirigida a propósito como el turno
    // actual del despacho secuencial dejan driver_user_id en mí.
    router.post(route('ride-requests.reject', id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            dismissIncomingRideRequest(id);
            // `broadcast(...)->toOthers()` no vuelve a la misma pestaña que
            // hizo el POST. Avisamos al Dashboard local para que quite su
            // tarjeta y contador sin esperar una recarga.
            window.dispatchEvent(new CustomEvent('arka:ride-request-answered', { detail: { id } }));
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <BottomSheet :show="show" :closeable="false">
        <div v-if="current" class="flex max-h-[80vh] flex-col">
            <div class="flex-1 overflow-y-auto px-4 pb-3 pt-2 sm:px-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-arka-primary">Nueva solicitud</p>
                    <h3 class="mt-0.5 text-xl font-semibold text-arka-text">
                        {{ current.is_scheduled ? 'Carrera programada' : '¿Toma esta carrera?' }}
                    </h3>
                </div>
                <!-- La X del modal también descarta (pedido explícito del usuario). -->
                <button
                    type="button"
                    class="text-arka-text-muted hover:text-arka-text text-xl leading-none px-2 -mt-1"
                    :disabled="processing"
                    @click="discard"
                >
                    &times;
                </button>
            </div>

            <p v-if="secondsLeft === 0" class="mt-2 text-sm font-medium text-arka-danger">
                ⏱ Se acabó el tiempo — pasando al siguiente conductor…
            </p>
            <p v-else-if="secondsLeft !== null" class="mt-1 text-sm font-medium" :class="secondsLeft <= 10 ? 'text-arka-danger' : 'text-arka-warning'">
                ⏱ Tiene {{ secondsLeft }} seg. para responder antes de que pase a otro conductor
            </p>

            <!-- La decisión principal debe entenderse antes de leer detalles:
                 primero ingreso, distancia y tiempo disponible. -->
            <div class="mt-4 grid grid-cols-[1fr_auto] items-center gap-3 rounded-2xl border border-arka-primary/25 bg-arka-primary/10 p-4">
                <div>
                    <p class="text-xs text-arka-text-muted">Usted recibe</p>
                    <!-- Bug real reportado por el usuario: current_offered_price
                         es solo el tramo final — sin sumar stops_price el
                         conductor veía menos de lo que en realidad le
                         corresponde por todo el recorrido con paradas. -->
                    <p class="text-3xl font-bold leading-none text-arka-primary-bright">
                        ${{ Number(current.total_offered_price ?? current.current_offered_price).toFixed(2) }}
                    </p>
                    <p class="mt-1 text-xs text-arka-text-muted">
                        {{ Number(current.distance_km).toFixed(1) }} km<span v-if="current.stops?.length"> + {{ current.stops.length }} parada{{ current.stops.length === 1 ? '' : 's' }}</span> · <span class="capitalize">{{ current.payment_method ?? 'efectivo' }}</span>
                    </p>
                </div>
                <div v-if="secondsLeft !== null" class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border-4 text-base font-bold"
                    :class="secondsLeft <= 10 ? 'border-arka-danger/40 text-arka-danger' : 'border-arka-warning/40 text-arka-warning'">
                    {{ secondsLeft }}s
                </div>
            </div>

            <div class="mt-4 space-y-4">
                <!-- Pedido explícito del usuario: que aparezca la foto del
                     cliente, no solo el nombre — mismo <UserAvatar> con
                     respaldo a iniciales que usa el resto de la app. -->
                <div class="flex items-center gap-3 rounded-arka bg-arka-base/45 p-3">
                    <UserAvatar
                        :user="{ name: current.client_name, avatar_url: current.client_avatar_url }"
                        size-class="h-14 w-14 text-lg shrink-0"
                    />
                    <p class="text-arka-text font-medium flex items-center gap-2 flex-wrap">
                        {{ current.client_name }}
                        <span
                            v-if="current.client_review_count > 0"
                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-arka-lime/15 text-arka-lime"
                        >
                            <span class="leading-none">★</span> {{ Number(current.client_rating).toFixed(1) }}
                        </span>
                        <span v-else class="text-xs text-arka-text-muted">Sin calificaciones todavía</span>
                        <span v-if="current.client_member_code" class="text-xs text-arka-text-muted">#{{ current.client_member_code }}</span>
                        <TrustScoreBadge :trust="current.client_trust" compact />
                    </p>
                </div>

                <!-- Origen/destino (pedido explícito del usuario: "mejorar para que
                     se visualice bien") — antes iban los dos en una sola línea
                     corrida con "→", ilegible cuando la dirección es larga y hace
                     wrap. Mismo lenguaje visual de "punto de origen (verde) → punto
                     de destino (rojo)" que ya usa el mapa (FleetMap.vue). -->
                <div class="flex gap-3 rounded-arka border border-arka-text-muted/10 p-3">
                    <div class="flex flex-col items-center pt-1.5 shrink-0">
                        <span class="h-2.5 w-2.5 rounded-full bg-arka-lime"></span>
                        <span class="w-px flex-1 min-h-[1.25rem] bg-arka-text-muted/30 my-1"></span>
                        <template v-for="stop in current.stops" :key="stop.sequence">
                            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                            <span class="w-px flex-1 min-h-[1.25rem] bg-arka-text-muted/30 my-1"></span>
                        </template>
                        <span class="h-2.5 w-2.5 rounded-full bg-arka-danger"></span>
                    </div>
                    <div class="flex-1 space-y-2.5 min-w-0">
                        <div>
                            <p class="text-arka-text font-medium truncate">
                                {{ current.origin_sector?.name ?? 'Origen sin sector' }}
                            </p>
                            <p class="text-xs text-arka-text-muted">{{ current.origin_address ?? 'Sin referencia' }}</p>
                        </div>
                        <!-- Bug real reportado por el usuario ("supongo que al
                             conductor tampoco le aparece"): antes las paradas
                             no salían acá — el conductor aceptaba sin saber
                             que el recorrido pasaba por otro lado antes del
                             destino, ni cuánto le tocaba por cada tramo. -->
                        <div v-for="stop in current.stops" :key="stop.sequence">
                            <p class="text-arka-text font-medium truncate">
                                Parada {{ stop.sequence }}{{ stop.leg_distance_km != null ? ` · ${stop.leg_distance_km.toFixed(1)} km` : '' }}
                                <span class="font-semibold text-amber-600">· ${{ Number(stop.leg_price).toFixed(2) }}</span>
                            </p>
                            <p class="text-xs text-arka-text-muted">{{ stop.address ?? 'Sin referencia' }}</p>
                        </div>
                        <div>
                            <p class="text-arka-text font-medium truncate">
                                {{ current.destination_sector?.name ?? 'Destino sin sector' }}
                            </p>
                            <p class="text-xs text-arka-text-muted">{{ current.destination_address ?? 'Sin referencia' }}</p>
                        </div>
                    </div>
                </div>
                <p v-if="current.is_scheduled" class="text-xs text-arka-warning font-medium">
                    📅 Programada para {{ formatScheduledAt(current.scheduled_at) }}
                    <span v-if="current.round_trip">· Ida y vuelta</span>
                </p>

                <!-- Observación del cliente (pedido explícito del usuario): antes
                     de aceptar, no solo después. -->
                <p v-if="current.notes" class="text-sm text-arka-text-muted italic">
                    "{{ current.notes }}"
                </p>
            </div>
            </div>

            <!-- Acciones pegadas al borde inferior: siempre quedan al alcance
                 del pulgar aunque una dirección o nota sea extensa. -->
            <div class="shrink-0 border-t border-arka-text-muted/10 bg-arka-card px-4 pb-4 pt-3 sm:px-6">
                <PrimaryButton class="min-h-12 w-full justify-center text-sm" :disabled="processing || secondsLeft === 0" @click="accept">
                    {{ processing ? 'Procesando…' : 'Aceptar carrera' }}
                </PrimaryButton>
                <button
                    type="button"
                    class="mt-2 min-h-10 w-full text-sm font-medium text-arka-text-muted hover:text-arka-danger disabled:opacity-50"
                    :disabled="processing || secondsLeft === 0"
                    @click="discard"
                >
                    No puedo tomarla
                </button>
            </div>
        </div>
    </BottomSheet>
</template>
