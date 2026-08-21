<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Prende/apaga la disponibilidad del conductor y, mientras está disponible,
// manda su ubicación al backend cada tanto usando la geolocalización del
// navegador (sección 9.3: "enviada periódicamente al backend").
const props = defineProps({
    initialAvailable: {
        type: Boolean,
        default: false,
    },
    canConnect: {
        type: Boolean,
        default: true,
    },
    blockedReason: {
        type: String,
        default: '',
    },
});

// Le avisa al padre cuando cambia (consideración agregada al alcance): así una
// pantalla que envuelve este switch en un banner más grande (ej. el inicio
// del conductor, "Activarme"/"Desconectarme") puede mostrar el texto correcto
// sin duplicar el estado acá y allá.
const emit = defineEmits(['update:available']);

const available = ref(props.initialAvailable);
// Si el backend ya informó que no puede conectarse, la causa debe verse de
// inmediato. Antes el botón HTML estaba `disabled`: eso también anulaba el
// click que intentaba explicar el bloqueo y parecía que no hacía nada.
const error = ref(props.canConnect ? '' : props.blockedReason);
const sending = ref(false);

let watchId = null;
let lastSentAt = 0;
const MIN_SECONDS_BETWEEN_UPDATES = 15;

// Última posición conocida de esta sesión (bug real encontrado: si al
// desactivarse el GPS falla justo en ese instante — un túnel, un edificio —
// `getCurrentPosition` no devuelve nada y el backend, que EXIGE lat/lng
// [DriverLocationController::update], nunca se entera de que hay que apagar
// `is_available`. El conductor queda mostrado "disponible" para siempre en
// toda la app aunque su propia pantalla ya diga "No disponible"). Se manda
// esta posición vieja en vez de nada — sigue siendo mejor que dejar el
// backend desactualizado.
let lastKnownPosition = null;

function sendLocation(lat, lng, isAvailable) {
    sending.value = true;
    window.axios
        .post(route('driver.location.update'), { lat, lng, is_available: isAvailable })
        .catch((e) => {
            // Pedido explícito del usuario: si el perfil de conductor está
            // incompleto (o la cuenta está suspendida), el backend rechaza
            // ponerse disponible (403) con un mensaje claro — sin esto, el
            // switch se quedaba mostrando "Disponible" en la pantalla aunque
            // el servidor nunca lo haya aceptado de verdad. Un corte de red
            // transitorio (sin response, o 5xx) NO fuerza la desconexión —
            // sigue intentando, como ya hacía antes de este fix.
            const isPermanentRejection = e.response?.status === 403;

            error.value = isPermanentRejection
                ? e.response.data.message
                : 'No pudimos actualizar su ubicación. Revise su conexión.';

            if (isAvailable && isPermanentRejection) {
                // Solo apaga el watch de geolocalización acá — no manda otro
                // ping (ya estamos en el manejo de este mismo error).
                if (watchId !== null) {
                    navigator.geolocation.clearWatch(watchId);
                    watchId = null;
                }
                available.value = false;
                emit('update:available', false);
            }
        })
        .finally(() => {
            sending.value = false;
        });
}

function handlePosition(position) {
    lastKnownPosition = position;

    const now = Date.now() / 1000;
    if (now - lastSentAt < MIN_SECONDS_BETWEEN_UPDATES) return;

    lastSentAt = now;
    sendLocation(position.coords.latitude, position.coords.longitude, true);
}

function startWatching() {
    if (!navigator.geolocation) {
        error.value = 'Su navegador no soporta geolocalización.';
        available.value = false;
        emit('update:available', false);
        return;
    }

    watchId = navigator.geolocation.watchPosition(
        handlePosition,
        () => {
            error.value = 'No pudimos acceder a su ubicación. Revise los permisos del navegador.';
            available.value = false;
            emit('update:available', false);
        },
        { enableHighAccuracy: true, maximumAge: 10_000 }
    );
}

// Botón de respaldo (pedido explícito del usuario, además del auto-resume
// de arriba): fuerza un ping ya mismo, sin esperar al próximo
// watchPosition() ni al límite de 15s entre envíos — para cuando el
// conductor quiere confirmar de una que sus clientes ya lo ven conectado,
// en vez de esperar a que el navegador decida mandar la próxima posición.
//
// Bug real reportado por el usuario ("no hace nada cuando lo oprimo"): este
// botón aparece justo cuando la ubicación está desactualizada, y la causa
// más común de eso es que un intento anterior de geolocalización falló
// (permiso denegado, GPS momentáneamente sin señal) — eso ya había puesto
// `available` en false acá adentro (ver el catch de startWatching()), aunque
// el conductor siga marcado disponible del lado del servidor. Antes, el
// guardia `!available.value` cortaba la función ahí mismo sin avisar nada.
// Ahora se intenta igual, y si la ubicación sí se puede obtener, se
// recupera el estado (reprende el switch y retoma watchPosition()) en vez
// de quedarse en silencio.
function refreshNow() {
    if (!props.canConnect) {
        error.value = props.blockedReason;
        return;
    }

    if (!navigator.geolocation) {
        error.value = 'Su navegador no soporta geolocalización.';
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            error.value = '';
            lastSentAt = Date.now() / 1000;

            if (!available.value) {
                available.value = true;
                emit('update:available', true);
                startWatching();
            }

            sendLocation(position.coords.latitude, position.coords.longitude, true);
        },
        () => {
            error.value = 'No pudimos acceder a su ubicación. Revise los permisos del navegador.';
        },
        { enableHighAccuracy: true, maximumAge: 0 }
    );
}

defineExpose({ refreshNow });

function stopWatching(lastPosition) {
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }

    if (lastPosition) {
        sendLocation(lastPosition.coords.latitude, lastPosition.coords.longitude, false);
    }
}

function toggle() {
    error.value = '';

    if (!available.value && !props.canConnect) {
        // Flujo directo: si falta un requisito, el conductor no debe quedarse
        // tocando un control bloqueado. El perfil explica el requisito exacto
        // con la misma regla del backend.
        window.location.assign(route('driver.profile.edit'));
        return;
    }

    available.value = !available.value;
    emit('update:available', available.value);

    if (available.value) {
        startWatching();
    } else {
        // Bug real reportado por el usuario ("le aparece desconectado y al
        // cliente le aparece activo"): `stopWatching()` recién corta
        // `watchId` DENTRO del callback de `getCurrentPosition()` de acá
        // abajo, que es async y puede tardar. Mientras tanto, el
        // `watchPosition()` de ANTES seguía escuchando — si el GPS del
        // navegador disparaba una posición nueva justo en esa ventana,
        // `handlePosition()` mandaba OTRO ping con `is_available:true` de
        // por medio, que podía llegar al backend después del de apagado
        // (no hay ningún orden garantizado entre dos pedidos). El backend
        // guarda tal cual lo último que le llega — quedaba "disponible" en
        // la base aunque la pantalla del conductor ya dijera lo contrario.
        // Cortando el watch ACÁ, sincrónico, antes de pedir la posición
        // fresca para el último ping, no queda ninguna ventana donde pueda
        // colarse otro ping de encendido.
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
        navigator.geolocation?.getCurrentPosition(
            (position) => stopWatching(position),
            () => stopWatching(lastKnownPosition)
        );
    }
}

// Bug reportado por el usuario: un conductor "disponible" que recarga la
// página (o vuelve de segundo plano) se quedaba con el switch prendido en
// pantalla, pero el watchPosition() del navegador se había perdido —
// startWatching() solo se llamaba desde toggle(), nunca al montar. Sin
// ningún ping nuevo, location_updated_at se iba quedando vieja hasta pasar
// los minutos de DriverProfile::isReachable() y sus clientes lo veían
// desconectado, sin que él pudiera notarlo sin tocar el switch dos veces.
onMounted(() => {
    if (props.initialAvailable && props.canConnect) {
        startWatching();
    }
});

onBeforeUnmount(() => {
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
    }
});
</script>

<template>
    <div class="flex max-w-sm flex-wrap items-center gap-x-3 gap-y-1">
        <button
            type="button"
            @click="toggle"
            :aria-disabled="!canConnect && !available"
            :title="!canConnect ? blockedReason : undefined"
            class="relative inline-flex h-7 w-12 items-center rounded-full transition-colors"
            :class="[
                available ? 'bg-arka-primary' : 'bg-arka-text-muted/30',
                !canConnect && !available ? 'cursor-help opacity-60' : '',
            ]"
        >
            <span
                class="inline-block h-5 w-5 transform rounded-full bg-arka-base transition-transform"
                :class="available ? 'translate-x-6' : 'translate-x-1'"
            />
        </button>

        <span class="text-sm font-medium" :class="available ? 'text-arka-primary-bright' : 'text-arka-text-muted'">
            {{ available ? 'Disponible' : 'No disponible' }}
        </span>

        <span v-if="sending" class="text-xs text-arka-text-muted">actualizando…</span>
        <span v-if="error" role="status" class="basis-full text-xs leading-snug text-arka-warning">
            {{ error }}
            <a
                v-if="!canConnect"
                :href="route('driver.profile.edit')"
                class="ml-1 font-semibold underline underline-offset-2 hover:text-arka-primary-bright"
            >
                Revisar mi perfil
            </a>
        </span>
    </div>
</template>
