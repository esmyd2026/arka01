<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import BottomSheet from '@/Components/BottomSheet.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { dismissIncomingRideRequest, incomingRideRequestState } from '@/Utils/incomingRideRequest';

// Pedido explícito del usuario: la carrera entrante tiene que ocupar media
// pantalla (no un renglón más en una lista), con Aceptar/Descartar y también
// la X del modal para descartarla — se muestra sin importar en qué pantalla
// esté el conductor (montado una sola vez en AuthenticatedLayout.vue).
const current = computed(() => incomingRideRequestState.queue[0] ?? null);
const show = computed(() => current.value !== null);
const processing = ref(false);

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
        onFinish: () => {
            processing.value = false;
            dismissIncomingRideRequest(id);
        },
    });
}
</script>

<template>
    <BottomSheet :show="show" :closeable="false">
        <div v-if="current" class="min-h-[50vh] p-4 sm:p-6 flex flex-col">
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-lg font-semibold text-arka-text">¡Carrera nueva!</h3>
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

            <p v-if="secondsLeft === 0" class="mt-1 text-sm font-medium text-arka-danger">
                ⏱ Se acabó el tiempo — pasando al siguiente conductor…
            </p>
            <p v-else-if="secondsLeft !== null" class="mt-1 text-sm font-medium" :class="secondsLeft <= 10 ? 'text-arka-danger' : 'text-arka-warning'">
                ⏱ Tiene {{ secondsLeft }} seg. para responder antes de que pase a otro conductor
            </p>

            <div class="mt-4 space-y-2">
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
                </p>

                <p v-if="current.origin_sector?.name || current.destination_sector?.name" class="text-arka-text font-medium">
                    {{ current.origin_sector?.name ?? 'origen sin sector' }} &rarr; {{ current.destination_sector?.name ?? 'destino sin sector' }}
                </p>
                <p class="text-sm text-arka-text-muted">
                    {{ current.origin_address ?? 'Origen sin referencia' }} &rarr;
                    {{ current.destination_address ?? 'Destino sin referencia' }}
                    · {{ Number(current.distance_km).toFixed(1) }} km
                </p>

                <p class="text-2xl font-semibold text-arka-primary-bright mt-2">
                    ${{ Number(current.current_offered_price).toFixed(2) }}
                </p>
                <!-- Forma de pago (pedido explícito del usuario): antes de
                     aceptar, no solo en el detalle. -->
                <p class="text-sm text-arka-text-muted">
                    Paga con <span class="capitalize text-arka-text">{{ current.payment_method ?? 'efectivo' }}</span>
                </p>

                <p v-if="current.is_scheduled" class="text-xs text-arka-warning font-medium">
                    📅 Programada
                </p>
            </div>

            <div class="mt-auto pt-6 grid grid-cols-2 gap-3">
                <DangerButton class="justify-center" :disabled="processing || secondsLeft === 0" @click="discard">Descartar</DangerButton>
                <PrimaryButton class="justify-center" :disabled="processing || secondsLeft === 0" @click="accept">Aceptar</PrimaryButton>
            </div>
        </div>
    </BottomSheet>
</template>
