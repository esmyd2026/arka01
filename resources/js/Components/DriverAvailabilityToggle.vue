<script setup>
import { onBeforeUnmount, ref } from 'vue';

// Prende/apaga la disponibilidad del conductor y, mientras está disponible,
// manda su ubicación al backend cada tanto usando la geolocalización del
// navegador (sección 9.3: "enviada periódicamente al backend").
const props = defineProps({
    initialAvailable: {
        type: Boolean,
        default: false,
    },
});

// Le avisa al padre cuando cambia (consideración agregada al alcance): así una
// pantalla que envuelve este switch en un banner más grande (ej. el inicio
// del conductor, "Activarme"/"Desconectarme") puede mostrar el texto correcto
// sin duplicar el estado acá y allá.
const emit = defineEmits(['update:available']);

const available = ref(props.initialAvailable);
const error = ref('');
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
    available.value = !available.value;
    emit('update:available', available.value);

    if (available.value) {
        startWatching();
    } else {
        // Mandamos un último ping con is_available:false para que la flota
        // deje de verlo como disponible de inmediato. Si el GPS falla justo
        // ahora, usamos la última posición conocida en vez de no mandar nada
        // (el backend exige lat/lng — ver el comentario de lastKnownPosition).
        navigator.geolocation?.getCurrentPosition(
            (position) => stopWatching(position),
            () => stopWatching(lastKnownPosition)
        );
    }
}

onBeforeUnmount(() => {
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
    }
});
</script>

<template>
    <div class="flex items-center gap-3">
        <button
            type="button"
            @click="toggle"
            class="relative inline-flex h-7 w-12 items-center rounded-full transition-colors"
            :class="available ? 'bg-arka-primary' : 'bg-arka-text-muted/30'"
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
        <span v-if="error" class="text-xs text-arka-danger">{{ error }}</span>
    </div>
</template>
