<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const bytes = ref(0);
const open = ref(false);
const connectionType = ref('');
const saveData = ref(false);
const showHistory = ref(false);
const dailyUsage = ref({});
const rideUsage = ref({});
const currentRideId = ref(null);
let timer;
let baseBytes = 0;

const storageKey = 'arka-session-transfer-bytes';
const historyKey = 'arka-data-usage-history';
const meterKey = 'arka-data-usage-meter';

const localDate = (date = new Date()) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

function rideIdFromUrl() {
    return window.location.pathname.match(/\/carreras\/(\d+)/)?.[1] || null;
}

function recordHistory(rawBytes) {
    let meter = {};
    let history = { days: {}, rides: {} };

    try { meter = JSON.parse(localStorage.getItem(meterKey)) || {}; } catch { meter = {}; }
    try { history = JSON.parse(localStorage.getItem(historyKey)) || history; } catch { history = { days: {}, rides: {} }; }

    const previousRaw = meter.timeOrigin === performance.timeOrigin ? Number(meter.raw || 0) : 0;
    const delta = Math.max(0, rawBytes - previousRaw);
    const today = localDate();
    const rideId = rideIdFromUrl();

    history.days[today] = Number(history.days[today] || 0) + delta;
    if (rideId) history.rides[rideId] = Number(history.rides[rideId] || 0) + delta;

    // Solo hace falta un historial corto para orientación visual; evita que
    // el almacenamiento del dispositivo crezca indefinidamente.
    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - 30);
    history.days = Object.fromEntries(Object.entries(history.days).filter(([date]) => date >= localDate(cutoff)));
    history.rides = Object.fromEntries(Object.entries(history.rides).slice(-50));

    localStorage.setItem(historyKey, JSON.stringify(history));
    localStorage.setItem(meterKey, JSON.stringify({ timeOrigin: performance.timeOrigin, raw: rawBytes }));

    dailyUsage.value = history.days;
    rideUsage.value = history.rides;
    currentRideId.value = rideId;
}

function exposedTransferBytes() {
    if (!window.performance?.getEntriesByType) return 0;

    const navigation = performance.getEntriesByType('navigation');
    const resources = performance.getEntriesByType('resource');

    return [...navigation, ...resources].reduce((total, entry) => total + Number(entry.transferSize || 0), 0);
}

function update() {
    const rawBytes = exposedTransferBytes();
    bytes.value = baseBytes + rawBytes;
    recordHistory(rawBytes);
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    connectionType.value = connection?.effectiveType?.toUpperCase() || '';
    saveData.value = Boolean(connection?.saveData);

    // Conserva la estimación aunque el conductor recargue o cambie de una
    // navegación completa a otra durante la misma pestaña.
    sessionStorage.setItem(storageKey, JSON.stringify({
        timeOrigin: performance.timeOrigin,
        total: bytes.value,
    }));
}

const amount = computed(() => {
    return formatBytes(bytes.value);
});

function formatBytes(value) {
    if (value < 1024) return `${Math.round(value)} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(0)} KB`;
    if (value < 1024 * 1024 * 1024) return `${(value / 1024 / 1024).toFixed(1)} MB`;
    return `${(value / 1024 / 1024 / 1024).toFixed(2)} GB`;
}

const todayBytes = computed(() => Number(dailyUsage.value[localDate()] || 0));
const weekBytes = computed(() => {
    let total = 0;
    for (let offset = 0; offset < 7; offset += 1) {
        const date = new Date();
        date.setDate(date.getDate() - offset);
        total += Number(dailyUsage.value[localDate(date)] || 0);
    }
    return total;
});
const averageDailyBytes = computed(() => {
    const activeDays = recentDays.value.map((day) => day.bytes).filter((value) => value > 0);
    if (!activeDays.length) return 0;
    return activeDays.reduce((total, value) => total + value, 0) / activeDays.length;
});
const currentRideBytes = computed(() => currentRideId.value ? Number(rideUsage.value[currentRideId.value] || 0) : 0);
const recentDays = computed(() => Array.from({ length: 7 }, (_, offset) => {
    const date = new Date();
    date.setDate(date.getDate() - offset);
    const key = localDate(date);
    return { key, label: offset === 0 ? 'Hoy' : date.toLocaleDateString('es-EC', { weekday: 'short' }), bytes: Number(dailyUsage.value[key] || 0) };
}));

onMounted(() => {
    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey));
        if (saved?.timeOrigin !== performance.timeOrigin) baseBytes = Number(saved?.total || 0);
    } catch {
        baseBytes = 0;
    }

    update();
    timer = window.setInterval(update, 5000);
});

onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="inline-flex min-h-[40px] items-center gap-1.5 rounded-full px-2 text-xs font-semibold text-arka-text-muted transition hover:bg-arka-base hover:text-arka-primary"
            :title="`Consumo aproximado de hoy: ${formatBytes(todayBytes)}`"
            aria-label="Ver consumo aproximado de datos"
            @click="open = !open"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" d="M7 16V8m5 8V5m5 11v-5" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 20h16" />
            </svg>
            <span>≈{{ formatBytes(todayBytes) }}</span>
        </button>

        <div v-if="open" class="absolute right-0 top-11 z-[60] w-72 rounded-2xl border border-arka-primary/20 bg-arka-card p-4 shadow-2xl">
            <div class="flex items-start justify-between gap-3">
                <div><p class="text-sm font-semibold text-arka-text">Consumo de hoy</p><p class="mt-1 text-2xl font-bold text-arka-primary">≈ {{ formatBytes(todayBytes) }}</p><p class="mt-0.5 text-[10px] text-arka-text-muted">Promedio diario: ≈ {{ formatBytes(averageDailyBytes) }}</p><p class="mt-0.5 text-[10px] text-arka-text-muted/70">Comienza nuevamente cada día</p></div>
                <button type="button" class="text-arka-text-muted hover:text-arka-text" aria-label="Cerrar" @click="open = false">✕</button>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 text-[11px]">
                <span v-if="connectionType" class="rounded-full bg-arka-base px-2.5 py-1 text-arka-text-muted">Red {{ connectionType }}</span>
                <span v-if="saveData" class="rounded-full bg-arka-primary/10 px-2.5 py-1 text-arka-primary">Ahorro de datos activo</span>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-arka-base/60 px-2 py-2"><p class="text-xs font-bold text-arka-text">{{ amount }}</p><p class="text-[10px] text-arka-text-muted">Sesión</p></div>
                <div class="rounded-xl bg-arka-base/60 px-2 py-2"><p class="text-xs font-bold text-arka-text">{{ formatBytes(weekBytes) }}</p><p class="text-[10px] text-arka-text-muted">7 días</p></div>
                <div class="rounded-xl bg-arka-base/60 px-2 py-2"><p class="text-xs font-bold text-arka-text">{{ currentRideId ? formatBytes(currentRideBytes) : '—' }}</p><p class="text-[10px] text-arka-text-muted">Carrera actual</p></div>
            </div>
            <button type="button" class="mt-3 flex w-full items-center justify-between rounded-xl border border-arka-text-muted/15 px-3 py-2 text-xs font-semibold text-arka-text transition hover:border-arka-primary/35" @click="showHistory = !showHistory">
                <span>{{ showHistory ? 'Ocultar histórico' : 'Ver histórico' }}</span><span class="text-arka-primary">{{ showHistory ? '↑' : '↓' }}</span>
            </button>
            <div v-if="showHistory" class="mt-3 space-y-1.5 rounded-xl bg-arka-base/40 p-3">
                <div v-for="day in recentDays" :key="day.key" class="flex items-center justify-between text-[11px]"><span class="capitalize text-arka-text-muted">{{ day.label }}</span><span class="font-medium text-arka-text">{{ formatBytes(day.bytes) }}</span></div>
            </div>
            <p class="mt-3 text-xs leading-relaxed text-arka-text-muted">Este valor es referencial y le ayuda a conocer cuántos datos ha usado aproximadamente mientras utiliza Arka01.</p>
        </div>
    </div>
</template>
