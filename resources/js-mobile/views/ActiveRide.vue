<script setup>
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';
import { useRouter } from 'vue-router';
import { Geolocation } from '@capacitor/geolocation';
import {
    fetchRide,
    startRide,
    markHeadingToPassenger,
    markArrived,
    markPickedUp,
    completeRide,
    cancelRide,
    updateRideLocation,
    reviewRide,
    fetchRideMessages,
    sendRideMessage,
} from '../services/activeRide';
import { triggerSos } from '../services/sos';
import { uploadPaymentProof, confirmCashPayment } from '../services/payments';
import MobileShell from '../components/MobileShell.vue';
import MobileMap from '../components/MobileMap.vue';

// Mismas listas que App\Services\Ride\RideLifecycle (CLIENT_CANCEL_REASONS/
// DRIVER_CANCEL_REASONS) — solo texto de UI, la validación real sigue
// siendo del servidor.
const CLIENT_CANCEL_REASONS = [
    'Cambié de planes',
    'Encontré otro medio de transporte',
    'Pedí la carrera por error',
    'El conductor demoró demasiado',
    'Otro motivo',
];
const DRIVER_CANCEL_REASONS = [
    'Imprevisto personal',
    'Problema con el vehículo',
    'No voy a poder llegar a tiempo',
    'El cliente no responde o no aparece',
    'Motivo de seguridad',
    'Otro motivo',
];

// Misma lista que App\Services\Ride\RideLifecycle::EARLY_COMPLETION_REASONS
// — hace falta cuando el conductor completa lejos del destino (más de 20m).
const EARLY_COMPLETION_REASONS = [
    'El cliente pidió terminar el viaje antes de llegar',
    'El cliente no colocó la ubicación de destino correcta',
    'No se puede llegar hasta el punto exacto (acceso cerrado, obra, tráfico, etc.)',
    'Problema con el GPS del celular',
    'Otro motivo',
];

const STATUS_LABELS = {
    scheduled: 'Programada',
    in_progress: 'En curso',
    completed: 'Completada',
    cancelled: 'Cancelada',
};

const props = defineProps({ id: { type: [String, Number], required: true } });
const router = useRouter();

const ride = ref(null);
const loading = ref(true);
const error = ref(null);
const acting = ref(false);
const showCancelForm = ref(false);
const cancelReason = ref('');
const showCompletionReasonForm = ref(false);
const completionReason = ref('');
const currentDevicePosition = ref(null);
const reviewRating = ref(5);
const reviewReasonId = ref(null);
const reviewComment = ref('');
const reviewSending = ref(false);
const reviewSent = ref(false);

let pollTimer = null;
let watchId = null;
let lastSentAt = 0;
const MIN_SECONDS_BETWEEN_UPDATES = 15;

const cancelReasons = computed(() => (ride.value?.is_driver ? DRIVER_CANCEL_REASONS : CLIENT_CANCEL_REASONS));
const mapCenter = computed(() => {
    if (!ride.value) return null;
    if (ride.value.is_driver && currentDevicePosition.value) return currentDevicePosition.value;
    if (ride.value.driver?.current_lat != null) return { lat: ride.value.driver.current_lat, lng: ride.value.driver.current_lng };
    return { lat: ride.value.origin_lat, lng: ride.value.origin_lng };
});
const mapTarget = computed(() => {
    if (!ride.value) return null;
    return ride.value.picked_up_at
        ? { lat: ride.value.destination_lat, lng: ride.value.destination_lng }
        : { lat: ride.value.origin_lat, lng: ride.value.origin_lng };
});
const journeyTitle = computed(() => {
    if (!ride.value) return '';
    if (ride.value.status === 'completed') return 'Carrera completada';
    if (ride.value.status === 'cancelled') return 'Carrera cancelada';
    if (!ride.value.heading_to_passenger_at) return ride.value.is_driver ? 'Listo para ir por el pasajero' : 'Tu conductor prepara el viaje';
    if (!ride.value.arrived_at) return ride.value.is_driver ? 'Dirígete al punto de recogida' : 'Tu conductor va en camino';
    if (!ride.value.picked_up_at) return ride.value.is_driver ? 'Confirma cuando suba el pasajero' : 'Tu conductor llegó';
    return 'En camino al destino';
});

async function load() {
    try {
        ride.value = await fetchRide(props.id);
        // No se toca error.value en éxito: el sondeo de fondo corre cada
        // 5s y no debe taparle al usuario el mensaje de una acción que
        // acaba de fallar (ej. "completar" rechazado por estar lejos del
        // destino) antes de que le dé tiempo a leerlo.
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function schedulePoll() {
    clearTimeout(pollTimer);
    if (ride.value && ['scheduled', 'in_progress'].includes(ride.value.status)) {
        pollTimer = setTimeout(async () => {
            await load();
            await loadMessages();
            manageLocationWatch();
            schedulePoll();
        }, 5000);
    }
}

async function manageLocationWatch() {
    const shouldWatch = ride.value?.is_driver && ride.value?.status === 'in_progress';

    if (shouldWatch && watchId === null) {
        try {
            watchId = await Geolocation.watchPosition({ enableHighAccuracy: true }, handlePosition);
        } catch (e) {
            // Sin ubicación no se puede compartir el recorrido en vivo, pero
            // no bloquea el resto de las acciones de la carrera.
        }
    } else if (!shouldWatch && watchId !== null) {
        await Geolocation.clearWatch({ id: watchId });
        watchId = null;
    }
}

function handlePosition(position, err) {
    if (err || !position) return;

    currentDevicePosition.value = { lat: position.coords.latitude, lng: position.coords.longitude };

    const now = Date.now() / 1000;
    if (now - lastSentAt < MIN_SECONDS_BETWEEN_UPDATES) return;
    lastSentAt = now;

    updateRideLocation(props.id, position.coords.latitude, position.coords.longitude).catch(() => {});
}

onMounted(async () => {
    await load();
    await loadMessages();
    if (ride.value?.is_driver) currentDevicePosition.value = await currentPositionOrNull();
    await manageLocationWatch();
    schedulePoll();
});

onBeforeUnmount(async () => {
    clearTimeout(pollTimer);
    if (watchId !== null) await Geolocation.clearWatch({ id: watchId });
});

async function withAction(fn) {
    acting.value = true;
    error.value = null;
    try {
        ride.value = await fn();
        await manageLocationWatch();
        schedulePoll();
    } catch (e) {
        if (e.stateMayHaveChanged) {
            await load();
            error.value = null;
            await manageLocationWatch();
            schedulePoll();
        } else {
            error.value = e.message;
        }
    } finally {
        acting.value = false;
    }
}

async function currentPositionOrNull() {
    try {
        const position = await Geolocation.getCurrentPosition({ enableHighAccuracy: true });
        return { lat: position.coords.latitude, lng: position.coords.longitude };
    } catch (e) {
        return null;
    }
}

const start = () => withAction(() => startRide(props.id));
const headingToPassenger = () => withAction(() => markHeadingToPassenger(props.id));

async function arrived() {
    const position = await currentPositionOrNull();
    await withAction(() => markArrived(props.id, position?.lat, position?.lng));
}

async function pickedUp() {
    const position = await currentPositionOrNull();
    await withAction(() => markPickedUp(props.id, position?.lat, position?.lng));
}

let lastCompletionPosition = null;

async function complete() {
    const position = await currentPositionOrNull();
    lastCompletionPosition = position;

    acting.value = true;
    error.value = null;
    try {
        ride.value = await completeRide(props.id, position?.lat, position?.lng, null, null);
        await manageLocationWatch();
        schedulePoll();
    } catch (e) {
        if (e.stateMayHaveChanged) {
            await load();
            error.value = null;
            await manageLocationWatch();
            schedulePoll();
            return;
        }
        // Lejos del destino (más de 20m): el servidor pide un motivo antes
        // de dejar completar igual — se le ofrece el mismo selector que
        // usa la web en vez de solo mostrar el error y trabarlo acá.
        showCompletionReasonForm.value = /destino|motivo para completar/i.test(e.message);
        error.value = e.message;
    } finally {
        acting.value = false;
    }
}

async function confirmCompleteWithReason() {
    if (!completionReason.value) {
        error.value = 'Elegí un motivo.';
        return;
    }

    await withAction(() =>
        completeRide(props.id, lastCompletionPosition?.lat, lastCompletionPosition?.lng, completionReason.value, null)
    );
    showCompletionReasonForm.value = false;
}

// Botón SOS (aditivo, sección 8) — separado de `error`/`acting` para no
// interferir con el resto de las acciones del viaje si algo sale mal acá.
const sosSending = ref(false);
const sosResult = ref(null);
const sosConfirming = ref(false);

async function confirmSos() {
    sosSending.value = true;
    sosResult.value = null;
    try {
        const notified = await triggerSos(props.id);
        sosResult.value = notified > 0
            ? `Alerta enviada a ${notified} contacto(s) de confianza.`
            : 'Alerta registrada, pero no tienes contactos de confianza con correo cargado.';
    } catch (e) {
        sosResult.value = e.message || 'No se pudo activar la alerta.';
    } finally {
        sosSending.value = false;
        sosConfirming.value = false;
    }
}

async function confirmCancel() {
    if (!cancelReason.value) {
        error.value = 'Elegí un motivo.';
        return;
    }
    await withAction(() => cancelRide(props.id, cancelReason.value, null));
    showCancelForm.value = false;
}

async function submitReview() {
    if (reviewRating.value < 5 && !reviewReasonId.value) { error.value = 'Selecciona el motivo de la calificación.'; return; }
    reviewSending.value = true; error.value = null;
    try { await reviewRide(props.id, reviewRating.value, reviewReasonId.value, reviewComment.value); reviewSent.value = true; ride.value.has_reviewed = true; }
    catch (e) { error.value = e.message; }
    finally { reviewSending.value = false; }
}

// Chat temporal cliente↔conductor (sección 10 del roadmap de mejoras): solo
// existe mientras la carrera está programada o en curso — mismo criterio que
// Ride::chatIsOpen() (web). Se recarga junto con el resto del sondeo de 5s
// de arriba (ver schedulePoll()), no tiene su propio timer.
const chatOpen = computed(() => ['scheduled', 'in_progress'].includes(ride.value?.status));
const chatMessages = ref([]);
const chatBody = ref('');
const chatSending = ref(false);
const chatError = ref('');

// Respuestas rápidas (mismo criterio que Ride/Show.vue, web): distintas
// según el rol y si la carrera ya arrancó o sigue programada.
const QUICK_REPLIES_DRIVER = ['Voy en camino.', 'Estoy cerca.', 'Estoy en el punto de recogida.', 'No logro ubicarte.', 'Hay tráfico, llegaré en unos minutos.'];
const QUICK_REPLIES_CLIENT = ['¿Vienes en camino?', '¿Ya llegaste?', 'Estoy saliendo.', 'Estoy en el punto indicado.', 'No logro ubicarte.'];
const QUICK_REPLIES_DRIVER_SCHEDULED = ['Confirmo que voy a estar a la hora programada.', '¿Seguimos con la fecha y hora acordada?', 'Voy a llegar unos minutos tarde el día de la carrera.'];
const QUICK_REPLIES_CLIENT_SCHEDULED = ['¿Confirmamos que sigue siendo a la hora programada?', 'Necesito cambiar el horario, ¿puede?', 'Todo listo para la hora acordada.'];
const quickReplies = computed(() => {
    if (ride.value?.status === 'scheduled') return ride.value.is_driver ? QUICK_REPLIES_DRIVER_SCHEDULED : QUICK_REPLIES_CLIENT_SCHEDULED;
    return ride.value?.is_driver ? QUICK_REPLIES_DRIVER : QUICK_REPLIES_CLIENT;
});

async function loadMessages() {
    if (!chatOpen.value) return;
    try { chatMessages.value = await fetchRideMessages(props.id); }
    catch (e) { /* No bloquea el resto de la pantalla si el chat falla en cargar. */ }
}

async function sendChatMessage(text) {
    const body = (text ?? chatBody.value).trim();
    if (!body || chatSending.value) return;

    chatSending.value = true;
    chatError.value = '';
    try {
        const message = await sendRideMessage(props.id, body);
        chatMessages.value.push(message);
        chatBody.value = '';
    } catch (e) {
        chatError.value = e.message || 'No se pudo mandar el mensaje.';
    } finally {
        chatSending.value = false;
    }
}

// Pago (pedido explícito del usuario: "el tipo de pago si es transferencia
// que aparezca las cuentas del conductor, si es efectivo, si el conductor
// pertenece a una cooperativa"). Ver cuenta: disponible en cualquier
// carrera por transferencia ya recogida, sea de cooperativa o de un
// conductor independiente. El ciclo de comprobante/confirmación solo existe
// cuando `ride.cooperative` no es null — mismo criterio que
// App\Services\Ride\RidePaymentManager (un conductor independiente cobra
// directo, sin intermediario que verifique).
const showBankAccounts = ref(false);
const proofFile = ref(null);
const proofUploading = ref(false);
const proofError = ref('');
const cashConfirming = ref(false);
const cashError = ref('');

const paymentStatusMeta = computed(() => ({
    pending: { label: ride.value?.payment_method === 'efectivo' ? 'Pendiente de confirmar' : 'Falta comprobante', class: 'pending' },
    proof_submitted: { label: 'Comprobante en revisión', class: 'review' },
    confirmed: { label: 'Pagada', class: 'confirmed' },
    rejected: { label: 'Comprobante rechazado', class: 'rejected' },
}[ride.value?.payment_status ?? 'pending'] ?? { label: 'Pago pendiente', class: 'pending' }));

function selectProofFile(event) {
    proofFile.value = event.target.files?.[0] ?? null;
    proofError.value = '';
}

async function submitProof() {
    if (!proofFile.value) { proofError.value = 'Seleccioná una imagen del comprobante.'; return; }
    proofUploading.value = true;
    proofError.value = '';
    try {
        ride.value = await uploadPaymentProof(props.id, proofFile.value);
        proofFile.value = null;
    } catch (e) {
        proofError.value = e.message || 'No se pudo enviar el comprobante.';
    } finally {
        proofUploading.value = false;
    }
}

async function confirmCash() {
    cashConfirming.value = true;
    cashError.value = '';
    try { ride.value = await confirmCashPayment(props.id); }
    catch (e) { cashError.value = e.message || 'No se pudo confirmar el efectivo.'; }
    finally { cashConfirming.value = false; }
}
</script>

<template>
    <MobileShell :role="ride?.is_driver ? 'conductor' : 'cliente'">
    <main class="mobile-page active-page">
        <header class="page-heading"><p class="mobile-eyebrow">Seguimiento en vivo</p><h1 class="mobile-title">Tu carrera</h1><p>Consulta el estado y las acciones disponibles del viaje.</p></header>

        <p v-if="loading" class="hint">Cargando…</p>

        <template v-else-if="ride">
            <section class="journey-map mobile-card">
                <MobileMap :center="mapCenter" :destination="mapTarget" :zoom="15" />
                <div class="navigation-card">
                    <span class="nav-arrow">➤</span>
                    <div><small>{{ ride.picked_up_at ? 'DESTINO' : 'PRÓXIMO PASO' }}</small><strong>{{ journeyTitle }}</strong><p>{{ ride.picked_up_at ? ride.destination_address : (ride.origin_address || 'Punto de recogida') }}</p></div>
                </div>
            </section>

            <section class="journey-progress mobile-card">
                <div :class="{done:ride.heading_to_passenger_at}"><span>1</span><small>En camino</small></div>
                <i></i><div :class="{done:ride.arrived_at}"><span>2</span><small>Llegó</small></div>
                <i></i><div :class="{done:ride.picked_up_at}"><span>3</span><small>Recogido</small></div>
                <i></i><div :class="{done:ride.status==='completed'}"><span>4</span><small>Finalizado</small></div>
            </section>

            <section class="status mobile-card" :class="ride.status"><span class="pulse"></span><div><small>Estado actual</small><strong>{{ STATUS_LABELS[ride.status] ?? ride.status }}</strong></div></section>

            <section class="details mobile-card">
                <p><strong>{{ ride.is_driver ? 'Cliente' : 'Conductor' }}:</strong> {{ ride.is_driver ? ride.client.name : ride.driver.name }}</p>
                <p><strong>Destino:</strong> {{ ride.destination_address || '—' }}</p>
                <p><strong>Precio:</strong> ${{ ride.price }}</p>
                <p v-if="ride.distance_km"><strong>Distancia:</strong> {{ ride.distance_km }} km</p>
                <p><strong>Pago:</strong> {{ ride.payment_method === 'transferencia' ? 'Transferencia' : 'Efectivo' }}</p>
                <p v-if="ride.cooperative"><strong>Cooperativa:</strong> {{ ride.cooperative.name }}</p>
            </section>

            <!-- Pago (pedido explícito del usuario: transferencia muestra las
                 cuentas del conductor/cooperativa; efectivo lo confirma el
                 conductor cuando la carrera es de cooperativa). -->
            <section v-if="ride.payment_method === 'transferencia' && ride.picked_up_at && ride.transfer_accounts.length" class="payment-card mobile-card">
                <button type="button" class="payment-toggle" @click="showBankAccounts = !showBankAccounts">
                    <span>{{ showBankAccounts ? 'Ocultar cuenta para transferir' : 'Ver cuenta para transferir' }}</span>
                    <b>{{ showBankAccounts ? '▲' : '▼' }}</b>
                </button>
                <div v-if="showBankAccounts" class="payment-accounts">
                    <p v-if="ride.cooperative" class="payment-note">La cooperativa recibe el total y liquida internamente el valor correspondiente al conductor.</p>
                    <div v-for="account in ride.transfer_accounts" :key="account.id" class="bank-account" :class="{ favorite: account.is_favorite }">
                        <p class="bank-name"><span v-if="account.is_favorite">★</span>{{ account.bank_name }}</p>
                        <dl>
                            <div><dt>Titular</dt><dd>{{ account.account_holder_name }}</dd></div>
                            <div><dt>Tipo</dt><dd>{{ account.account_type === 'ahorros' ? 'Ahorros' : 'Corriente' }}</dd></div>
                            <div><dt>Número</dt><dd>{{ account.account_number }}</dd></div>
                            <div><dt>Cédula/RUC</dt><dd>{{ account.identity_number }}</dd></div>
                        </dl>
                    </div>
                </div>
            </section>

            <section v-if="ride.cooperative && ride.status === 'completed'" class="payment-card mobile-card">
                <div class="payment-status-row">
                    <span>Estado del pago</span>
                    <b class="payment-pill" :class="paymentStatusMeta.class">{{ paymentStatusMeta.label }}</b>
                </div>

                <template v-if="ride.payment_method === 'transferencia'">
                    <p v-if="ride.is_driver" class="hint">Se marcará como pagada cuando la cooperativa confirme el comprobante del cliente.</p>
                    <template v-else>
                        <p v-if="ride.payment_status === 'rejected'" class="error">{{ ride.payment_rejection_reason }}</p>
                        <form v-if="['pending', 'rejected'].includes(ride.payment_status)" class="proof-form" @submit.prevent="submitProof">
                            <label class="proof-picker">
                                <input type="file" accept="image/jpeg,image/png,image/webp" @change="selectProofFile">
                                {{ proofFile?.name || 'Seleccionar comprobante' }}
                            </label>
                            <p v-if="proofError" class="error">{{ proofError }}</p>
                            <button class="primary-btn" type="submit" :disabled="proofUploading">{{ proofUploading ? 'Enviando…' : 'Enviar comprobante' }}</button>
                        </form>
                        <a v-else-if="ride.payment_proof_url" :href="ride.payment_proof_url" target="_blank" class="secondary-btn proof-link">Ver comprobante enviado</a>
                    </template>
                </template>
                <template v-else>
                    <p v-if="!ride.is_driver" class="hint">El conductor confirmará cuando reciba el efectivo.</p>
                    <template v-else-if="ride.payment_status === 'pending'">
                        <p v-if="cashError" class="error">{{ cashError }}</p>
                        <button class="primary-btn" :disabled="cashConfirming" @click="confirmCash">{{ cashConfirming ? 'Confirmando…' : 'Confirmar que recibí el efectivo' }}</button>
                    </template>
                </template>
            </section>

            <p v-if="error" class="error">{{ error }}</p>

            <template v-if="ride.is_driver && ride.status === 'scheduled'">
                <button class="primary-btn" :disabled="acting" @click="start">Iniciar y abrir navegación</button>
            </template>

            <template v-else-if="ride.is_driver && ride.status === 'in_progress'">
                <button v-if="!ride.heading_to_passenger_at" class="secondary-btn" :disabled="acting" @click="headingToPassenger">
                    Voy por el pasajero
                </button>
                <button v-if="!ride.arrived_at" class="secondary-btn" :disabled="acting" @click="arrived">
                    Llegué
                </button>
                <button v-else-if="!ride.picked_up_at" class="secondary-btn" :disabled="acting" @click="pickedUp">
                    Recogido
                </button>
                <button v-if="!showCompletionReasonForm" class="primary-btn" :disabled="acting" @click="complete">
                    Completar carrera
                </button>
                <div v-else class="completion-form">
                    <p class="hint">Elegí por qué completás lejos del destino:</p>
                    <select v-model="completionReason">
                        <option value="" disabled>Elegí un motivo</option>
                        <option v-for="reason in EARLY_COMPLETION_REASONS" :key="reason" :value="reason">{{ reason }}</option>
                    </select>
                    <div class="cancel-actions">
                        <button class="primary-btn" :disabled="acting" @click="confirmCompleteWithReason">Completar igual</button>
                        <button class="ghost-btn" @click="showCompletionReasonForm = false">Volver</button>
                    </div>
                </div>
            </template>

            <section v-if="ride.status === 'in_progress'" class="sos-card mobile-card">
                <p v-if="sosResult" class="sos-result">{{ sosResult }}</p>
                <button v-if="!sosConfirming" class="sos-button" @click="sosConfirming = true">🆘 Botón de emergencia</button>
                <div v-else class="sos-confirm">
                    <p class="hint">Se avisará por correo a tus contactos de confianza con la ubicación del vehículo. ¿Activar la alerta?</p>
                    <div class="cancel-actions">
                        <button class="danger-btn" :disabled="sosSending" @click="confirmSos">{{ sosSending ? 'Enviando…' : 'Sí, activar alerta' }}</button>
                        <button class="ghost-btn" @click="sosConfirming = false">Cancelar</button>
                    </div>
                </div>
            </section>

            <!-- Chat temporal cliente↔conductor (sección 10 del roadmap de
                 mejoras): un hilo por carrera, se cierra solo al completar o
                 cancelar (ver Ride::chatIsOpen(), replicado en `chatOpen`). -->
            <section v-if="chatOpen" class="chat-card mobile-card">
                <p class="mobile-eyebrow">Mensajes</p>
                <div class="chat-list">
                    <p v-if="!chatMessages.length" class="hint">Todavía no hay mensajes.</p>
                    <div v-for="message in chatMessages" :key="message.id" class="chat-message" :class="{ mine: message.is_mine }">
                        <span class="chat-sender">{{ message.sender_name }}</span>
                        <p>{{ message.body }}</p>
                    </div>
                </div>
                <div class="quick-replies">
                    <button v-for="reply in quickReplies" :key="reply" type="button" :disabled="chatSending" @click="sendChatMessage(reply)">{{ reply }}</button>
                </div>
                <p v-if="chatError" class="error">{{ chatError }}</p>
                <form class="chat-form" @submit.prevent="sendChatMessage()">
                    <input v-model="chatBody" class="mobile-input" type="text" placeholder="Escribe un mensaje…" maxlength="500">
                    <button type="submit" :disabled="chatSending || !chatBody.trim()">➤</button>
                </form>
            </section>

            <template v-if="['scheduled', 'in_progress'].includes(ride.status)">
                <button v-if="!showCancelForm" class="cancel-link" @click="showCancelForm = true">Cancelar carrera</button>
                <div v-else class="cancel-form">
                    <select v-model="cancelReason">
                        <option value="" disabled>Elegí un motivo</option>
                        <option v-for="reason in cancelReasons" :key="reason" :value="reason">{{ reason }}</option>
                    </select>
                    <div class="cancel-actions">
                        <button class="danger-btn" :disabled="acting" @click="confirmCancel">Confirmar cancelación</button>
                        <button class="ghost-btn" @click="showCancelForm = false">Volver</button>
                    </div>
                </div>
            </template>

            <button
                v-if="['completed', 'cancelled'].includes(ride.status)"
                class="primary-btn"
                @click="router.push({ name: 'home' })"
            >
                Volver a inicio
            </button>

            <section v-if="ride.status === 'completed' && !ride.has_reviewed" class="review-card mobile-card">
                <p class="mobile-eyebrow">Tu opinión importa</p><h2>Calificar {{ ride.is_driver ? 'al cliente' : 'al conductor' }}</h2>
                <div class="stars"><button v-for="star in 5" :key="star" type="button" :class="{active:star<=reviewRating}" @click="reviewRating=star">★</button></div>
                <label v-if="reviewRating < 5" class="mobile-field"><span>¿Qué se puede mejorar?</span><select v-model="reviewReasonId" class="mobile-input"><option :value="null" disabled>Selecciona un motivo</option><option v-for="reason in ride.rating_reasons" :key="reason.id" :value="reason.id">{{ reason.text }}</option></select></label>
                <label class="mobile-field"><span>Comentario (opcional)</span><textarea v-model="reviewComment" class="mobile-input" rows="3" placeholder="Cuéntanos cómo fue el viaje"></textarea></label>
                <button class="mobile-button" :disabled="reviewSending" @click="submitReview">{{ reviewSending ? 'Enviando…' : 'Enviar calificación' }}</button>
            </section>
            <p v-else-if="ride.status === 'completed' && (ride.has_reviewed || reviewSent)" class="review-thanks mobile-card">✓ Gracias, tu calificación fue registrada.</p>
        </template>
    </main>
    </MobileShell>
</template>

<style scoped>
/* Presentación compacta para el recorrido, sin modificar su lógica de estados. */
.active-page { display: grid; gap: 1rem; font-family: inherit; }.page-heading > p:last-child { margin: .5rem 0 0; color: var(--arka-muted); font-size: .8rem; }.hint { color: var(--arka-muted); }.error { margin: 0; padding: .75rem; border: 1px solid rgba(248,113,113,.3); border-radius: .8rem; background: rgba(248,113,113,.08); color: #fca5a5; }
.journey-map{position:relative;width:100%;min-width:0;max-width:100%;height:48dvh;min-height:20rem;overflow:hidden}.navigation-card{position:absolute;left:.75rem;right:.75rem;bottom:.75rem;min-width:0;padding:.8rem;display:grid;grid-template-columns:auto minmax(0,1fr);gap:.75rem;border-radius:1rem;background:var(--arka-chrome);box-shadow:var(--arka-shadow)}.nav-arrow{width:2.4rem;height:2.4rem;display:grid;place-items:center;border-radius:.75rem;background:var(--arka-primary);color:#fff;transform:rotate(-35deg)}.navigation-card div{min-width:0;display:grid;gap:.12rem}.navigation-card small{color:var(--arka-primary);font-size:.58rem;font-weight:850;letter-spacing:.12em}.navigation-card strong{min-width:0;overflow:hidden;text-overflow:ellipsis;font-size:.85rem}.navigation-card p{margin:0;color:var(--arka-muted);font-size:.68rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.journey-progress{padding:.8rem;display:grid;grid-template-columns:auto 1fr auto 1fr auto 1fr auto;align-items:start;gap:.25rem}.journey-progress div{display:grid;justify-items:center;gap:.25rem}.journey-progress span{width:1.55rem;height:1.55rem;display:grid;place-items:center;border-radius:50%;background:var(--arka-surface);color:var(--arka-muted);font-size:.65rem;font-weight:850}.journey-progress small{font-size:.53rem;color:var(--arka-muted)}.journey-progress .done span{background:var(--arka-primary);color:#fff}.journey-progress .done small{color:var(--arka-primary)}.journey-progress i{height:2px;margin-top:.72rem;background:var(--arka-border)}
.status { margin: 0; padding: .85rem 1rem; display: flex; align-items: center; gap: .75rem; border-color:color-mix(in srgb,var(--arka-primary) 30%,transparent);background:var(--arka-status-panel);color: var(--arka-text);font-size:1rem;font-weight:600;border-radius:1rem}.status .pulse { width: .72rem; height: .72rem; border-radius: 50%; background: var(--arka-primary); box-shadow: 0 0 0 5px var(--arka-primary-soft); }.status > div { display: grid; gap: .15rem; }.status small { color: var(--arka-muted); font-size: .62rem; text-transform: uppercase; }.status strong { font-size: .9rem; }.status.cancelled { border-color:color-mix(in srgb,var(--arka-danger) 30%,transparent);background:color-mix(in srgb,var(--arka-danger) 8%,var(--arka-card))}.status.completed { background: var(--arka-primary-soft); color: var(--arka-text); }
.details { padding: .45rem 1rem; }.details p { min-height: 3rem; margin: 0; display: flex; align-items: center; justify-content: space-between; gap: 1rem; border-bottom: 1px solid var(--arka-border); color: var(--arka-text); font-size: .76rem; text-align: right; }.details p:last-child { border: 0; }.details p strong { color: var(--arka-muted); font-size: .67rem; font-weight: 600; text-align: left; }
.primary-btn, .secondary-btn { display:block;width:100%;padding:.85rem;border:0;min-height: 3.2rem; margin: 0; border-radius: .9rem; background: var(--arka-primary); color:var(--arka-on-primary);font-weight: 800; }.secondary-btn { border: 1px solid color-mix(in srgb,var(--arka-primary) 35%,transparent); background: var(--arka-primary-soft); color: var(--arka-primary); }.primary-btn:disabled,.secondary-btn:disabled{opacity:.6}.cancel-link { display:block;width:100%;border:0;background:transparent;margin-top: .25rem; color: var(--arka-danger); }.cancel-form, .completion-form { margin: 0; padding: 1rem; border: 1px solid var(--arka-border); border-radius: 1rem; background:var(--arka-card); }.cancel-form select, .completion-form select {width:100%;padding:.6rem;min-height:3rem;border:1px solid var(--arka-border);border-radius:.8rem;background:var(--arka-field);color:var(--arka-text)}.cancel-actions{display:flex;gap:.5rem;margin-top:.75rem}.danger-btn{flex:1;padding:.6rem;border:0;border-radius:.8rem;background:var(--arka-danger);color:#fff}.ghost-btn{padding:.6rem 1rem;border:1px solid var(--arka-border);border-radius:.8rem;background:transparent;color:var(--arka-muted)}
.sos-card { padding: 1rem; border-color: rgba(248,113,113,.3); background: rgba(248,113,113,.06); display: grid; gap: .6rem; }.sos-button { min-height: 3rem; border: 1px solid var(--arka-danger); border-radius: .9rem; background: transparent; color: var(--arka-danger); font-weight: 800; }.sos-confirm { display: grid; gap: .6rem; }.sos-confirm .hint { margin: 0; color: var(--arka-text); font-size: .8rem; line-height: 1.4; }.sos-result { margin: 0; color: var(--arka-danger); font-size: .8rem; font-weight: 700; }
.review-card{padding:1rem;display:grid;gap:.85rem}.review-card h2{margin:.2rem 0 0;font-size:1.05rem}.stars{display:flex;justify-content:center;gap:.35rem}.stars button{border:0;background:transparent;color:#cbd5cf;font-size:2rem}.stars button.active{color:#f5b82e}.review-thanks{margin:0;padding:1rem;color:var(--arka-primary);font-weight:750;text-align:center}

.payment-card{padding:1rem;display:grid;gap:.75rem}
.payment-toggle{width:100%;padding:0;border:0;background:transparent;display:flex;align-items:center;justify-content:space-between;color:var(--arka-primary);font-weight:800;font-size:.85rem}
.payment-accounts{display:grid;gap:.65rem}
.payment-note{margin:0;padding:.6rem .75rem;border-radius:.75rem;background:var(--arka-primary-soft);color:var(--arka-muted);font-size:.72rem;line-height:1.4}
.bank-account{padding:.85rem;border:1px solid var(--arka-border);border-radius:.9rem}.bank-account.favorite{border-color:var(--arka-primary)}
.bank-name{margin:0 0 .5rem;display:flex;align-items:center;gap:.35rem;color:var(--arka-text);font-weight:800;font-size:.85rem}.bank-name span{color:var(--arka-primary)}
.bank-account dl{margin:0;display:grid;gap:.35rem}.bank-account dl>div{display:flex;justify-content:space-between;gap:.75rem;font-size:.78rem}.bank-account dt{color:var(--arka-muted)}.bank-account dd{margin:0;color:var(--arka-text);font-weight:700;text-align:right}
.payment-status-row{display:flex;align-items:center;justify-content:space-between;gap:.75rem;font-size:.82rem;font-weight:700;color:var(--arka-text)}
.payment-pill{padding:.3rem .65rem;border-radius:999px;font-size:.65rem;font-weight:800}
.payment-pill.pending{background:color-mix(in srgb,var(--arka-warning) 12%,transparent);color:var(--arka-warning)}
.payment-pill.review{background:color-mix(in srgb,#38bdf8 15%,transparent);color:#38bdf8}
.payment-pill.confirmed{background:var(--arka-primary-soft);color:var(--arka-primary)}
.payment-pill.rejected{background:color-mix(in srgb,var(--arka-danger) 12%,transparent);color:var(--arka-danger)}
.proof-form{display:grid;gap:.65rem}
.proof-picker{position:relative;padding:1rem;display:block;border:1px dashed color-mix(in srgb,var(--arka-primary) 35%,transparent);border-radius:.9rem;background:var(--arka-primary-soft);color:var(--arka-text);font-size:.8rem;text-align:center}
.proof-picker input{position:absolute;inset:0;opacity:0;cursor:pointer}
.proof-link{display:block;text-align:center;text-decoration:none}

.chat-card{padding:1rem;display:grid;gap:.75rem}
.chat-list{max-height:16rem;overflow-y:auto;display:grid;gap:.5rem}
.chat-message{max-width:82%;padding:.55rem .75rem;border-radius:.9rem;background:var(--arka-field);justify-self:start}
.chat-message.mine{background:var(--arka-primary-soft);justify-self:end}
.chat-sender{display:block;margin-bottom:.15rem;color:var(--arka-muted);font-size:.62rem;font-weight:800;text-transform:uppercase}
.chat-message p{margin:0;color:var(--arka-text);font-size:.82rem;line-height:1.4;overflow-wrap:anywhere}
.quick-replies{display:flex;flex-wrap:wrap;gap:.4rem}
.quick-replies button{padding:.4rem .7rem;border:1px solid var(--arka-border);border-radius:999px;background:transparent;color:var(--arka-muted);font-size:.68rem}
.chat-form{display:flex;gap:.5rem}
.chat-form input{flex:1}
.chat-form button{width:2.9rem;height:2.9rem;flex:0 0 auto;border:0;border-radius:.8rem;background:var(--arka-primary);color:var(--arka-on-primary);font-size:1.1rem}
.chat-form button:disabled{opacity:.5}
</style>
