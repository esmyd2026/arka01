<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    summary: { type: Object, required: true },
    people: { type: Array, required: true },
    receivedRequests: { type: Array, required: true },
    sentRequests: { type: Array, required: true },
    recommendedDrivers: { type: Array, required: true },
    canBuildFleet: { type: Boolean, required: true },
});

const search = ref('');
const searchResults = ref([]);
const searching = ref(false);
const searchError = ref('');
const openPerson = ref(null);
const privacy = reactive(Object.fromEntries(props.people.map((person) => [person.connection_public_id, {
    relationship_label: person.relationship_label || '',
    share_fleet: person.my_privacy.share_fleet,
    share_rating: person.my_privacy.share_rating,
}])));

let searchTimer;
let searchRequest = 0;

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchError.value = '';
    if (value.trim().length < 2) {
        searchResults.value = [];
        searching.value = false;
        return;
    }
    searchTimer = setTimeout(runSearch, 300);
});

async function runSearch() {
    const requestId = ++searchRequest;
    searching.value = true;
    try {
        const response = await window.axios.get(route('trust-circle.search'), { params: { q: search.value.trim() } });
        if (requestId === searchRequest) searchResults.value = response.data.people;
    } catch (error) {
        if (requestId === searchRequest) searchError.value = 'No pudimos buscar ahora. Inténtalo nuevamente.';
    } finally {
        if (requestId === searchRequest) searching.value = false;
    }
}

function sendRequest(person) {
    router.post(route('trust-circle.store'), { user_public_id: person.user_public_id }, {
        preserveScroll: true,
        onSuccess: () => { search.value = ''; searchResults.value = []; },
    });
}

function respond(request, action) {
    router.post(route('trust-circle.respond', request.connection_public_id), { action }, { preserveScroll: true });
}

function savePrivacy(person) {
    router.put(route('trust-circle.settings.update', person.connection_public_id), privacy[person.connection_public_id], { preserveScroll: true });
}

async function removeConnection(person) {
    if (!(await confirmDialog(`¿Quitar a ${person.name} de tu círculo?`, { danger: true }))) return;
    router.delete(route('trust-circle.destroy', person.connection_public_id), { preserveScroll: true });
}

function inviteDriver(driver) {
    router.post(route('trust-circle.drivers.invite'), { driver_public_id: driver.driver_public_id }, { preserveScroll: true });
}

function scoreStyle(score) {
    return { '--score': `${Math.max(0, Math.min(100, score)) * 3.6}deg` };
}
</script>

<template>
    <Head title="Círculo de confianza" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-arka-primary">Red privada</p>
                <h2 class="mt-1 text-xl font-semibold leading-tight text-arka-text">Círculo de confianza</h2>
            </div>
        </template>

        <main class="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-10">
            <section class="overflow-hidden rounded-arka border border-arka-primary/20 bg-gradient-to-br from-arka-primary/15 via-arka-card to-arka-card p-5 shadow-2xl shadow-black/10 sm:p-7">
                <div class="grid gap-6 lg:grid-cols-[1fr_auto] lg:items-center">
                    <div class="max-w-2xl">
                        <span class="inline-flex items-center gap-2 rounded-full bg-arka-primary/10 px-3 py-1 text-xs font-semibold text-arka-primary">
                            <svg class="h-4 w-4 fill-none stroke-current" viewBox="0 0 24 24"><path d="M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-1a3.5 3.5 0 1 0 0-7M2 21a6 6 0 0 1 12 0m1-7a5 5 0 0 1 7 4.6" stroke-width="1.7" stroke-linecap="round" /></svg>
                            Personas reales, conexiones aceptadas
                        </span>
                        <h1 class="mt-4 text-2xl font-bold tracking-tight text-arka-text sm:text-3xl">La confianza también se construye en comunidad.</h1>
                        <p class="mt-3 max-w-xl text-sm leading-6 text-arka-text-muted">Conecta con familiares y amigos, descubre qué conductores conocen y decide qué información compartes con cada persona.</p>
                        <div class="mt-5 grid max-w-lg grid-cols-2 gap-3 sm:grid-cols-3">
                            <div class="rounded-arka bg-black/15 p-3"><strong class="block text-xl text-arka-text">{{ summary.people }}</strong><span class="text-xs text-arka-text-muted">Personas conectadas</span></div>
                            <div class="rounded-arka bg-black/15 p-3"><strong class="block text-xl text-arka-text">{{ recommendedDrivers.length }}</strong><span class="text-xs text-arka-text-muted">Conductores sugeridos</span></div>
                            <div class="col-span-2 rounded-arka bg-black/15 p-3 sm:col-span-1"><strong class="block text-xl text-arka-text">{{ summary.shared_drivers }}</strong><span class="text-xs text-arka-text-muted">Vínculos de flota compartidos</span></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 rounded-arka border border-white/5 bg-black/20 p-4">
                        <div class="score-ring" :style="scoreStyle(summary.own_trust.score)"><div><strong>{{ summary.own_trust.score }}</strong><small>/100</small></div></div>
                        <div><span class="text-xs text-arka-text-muted">Tu índice actual</span><strong class="mt-1 block text-lg text-arka-text">{{ summary.own_trust.level }}</strong><button class="mt-2 text-xs font-semibold text-arka-primary" @click="openPerson = openPerson === 'own' ? null : 'own'">Ver cómo se calcula</button></div>
                    </div>
                </div>
                <div v-if="openPerson === 'own'" class="mt-5 grid gap-2 border-t border-white/10 pt-5 sm:grid-cols-4">
                    <div v-for="part in summary.own_trust.components" :key="part.key" class="rounded-arka bg-black/15 p-3"><div class="flex justify-between text-xs"><span class="text-arka-text-muted">{{ part.label }}</span><strong class="text-arka-text">{{ part.points }}/{{ part.maximum }}</strong></div><div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/10"><i class="block h-full rounded-full bg-arka-primary" :style="{ width: `${part.points / part.maximum * 100}%` }" /></div></div>
                    <p class="sm:col-span-4 text-xs leading-5 text-arka-text-muted">Es una referencia explicable basada en actividad dentro de Arka01. No certifica identidad ni garantiza seguridad.</p>
                </div>
            </section>

            <section v-if="receivedRequests.length" class="rounded-arka border border-arka-primary/25 bg-arka-card p-4 sm:p-5">
                <div class="mb-4"><p class="text-[10px] font-bold uppercase tracking-[.16em] text-arka-primary">Pendientes</p><h3 class="mt-1 text-lg font-semibold text-arka-text">Quieren entrar a tu círculo</h3></div>
                <div class="grid gap-3 md:grid-cols-2">
                    <article v-for="request in receivedRequests" :key="request.connection_public_id" class="flex items-center gap-3 rounded-arka border border-white/5 bg-black/15 p-3">
                        <img v-if="request.avatar_url" :src="request.avatar_url" class="h-11 w-11 rounded-full object-cover" alt="">
                        <span v-else class="grid h-11 w-11 place-items-center rounded-full bg-arka-primary/10 font-bold text-arka-primary">{{ request.name.charAt(0) }}</span>
                        <div class="min-w-0 flex-1"><strong class="block truncate text-sm text-arka-text">{{ request.name }}</strong><span class="text-xs text-arka-text-muted">@{{ request.username }}</span></div>
                        <button class="rounded-lg bg-arka-primary px-3 py-2 text-xs font-bold text-arka-base" @click="respond(request, 'accept')">Aceptar</button>
                        <button class="px-2 py-2 text-xs text-arka-text-muted" @click="respond(request, 'reject')">Ahora no</button>
                    </article>
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-[.9fr_1.1fr]">
                <div class="rounded-arka border border-white/5 bg-arka-card p-4 sm:p-5">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-arka-primary">Agregar persona</p>
                    <h3 class="mt-1 text-lg font-semibold text-arka-text">Encuentra a alguien que conoces</h3>
                    <p class="mt-2 text-xs leading-5 text-arka-text-muted">Por privacidad, busca su usuario exacto o código de socio. Nadie entra sin aceptar.</p>
                    <div class="relative mt-4"><svg class="pointer-events-none absolute left-3 top-3.5 h-5 w-5 fill-none stroke-arka-text-muted" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" stroke-width="1.7"/><path d="m16 16 4 4" stroke-width="1.7" stroke-linecap="round"/></svg><input v-model="search" class="w-full rounded-arka border border-arka-text-muted/20 bg-arka-base py-3 pl-10 pr-24 text-sm text-arka-text placeholder:text-arka-text-muted/70 focus:border-arka-primary focus:ring-arka-primary" placeholder="@usuario o código"><span v-if="searching" class="absolute right-3 top-3.5 text-xs text-arka-primary">Buscando…</span></div>
                    <p v-if="searchError" class="mt-2 text-xs text-red-300">{{ searchError }}</p>
                    <div v-if="searchResults.length" class="mt-3 divide-y divide-white/5 overflow-hidden rounded-arka border border-white/5">
                        <article v-for="person in searchResults" :key="person.user_public_id" class="flex items-center gap-3 bg-black/10 p-3">
                            <img v-if="person.avatar_url" :src="person.avatar_url" class="h-10 w-10 rounded-full object-cover" alt=""><span v-else class="grid h-10 w-10 place-items-center rounded-full bg-arka-primary/10 font-bold text-arka-primary">{{ person.name.charAt(0) }}</span>
                            <div class="min-w-0 flex-1"><strong class="block truncate text-sm text-arka-text">{{ person.name }}</strong><span class="text-xs text-arka-text-muted">@{{ person.username }} · {{ person.role }}</span></div>
                            <span v-if="person.connection_status" class="text-xs text-arka-text-muted">{{ person.connection_status === 'accepted' ? 'Ya conectado' : 'Pendiente' }}</span><button v-else class="rounded-lg bg-arka-primary px-3 py-2 text-xs font-bold text-arka-base" @click="sendRequest(person)">Conectar</button>
                        </article>
                    </div>
                    <div v-if="sentRequests.length" class="mt-5 border-t border-white/5 pt-4"><span class="text-xs font-semibold text-arka-text">Solicitudes enviadas</span><div class="mt-2 flex flex-wrap gap-2"><span v-for="request in sentRequests" :key="request.connection_public_id" class="rounded-full bg-white/5 px-3 py-1.5 text-xs text-arka-text-muted">{{ request.name }} · pendiente</span></div></div>
                </div>

                <div class="rounded-arka border border-white/5 bg-arka-card p-4 sm:p-5">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-arka-primary">Privacidad clara</p><h3 class="mt-1 text-lg font-semibold text-arka-text">Tú decides qué pueden ver</h3>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2"><div class="rounded-arka bg-black/15 p-3"><strong class="text-sm text-arka-text">Índice y calificación</strong><p class="mt-1 text-xs leading-5 text-arka-text-muted">Permite mostrar tu reputación y las señales que la explican.</p></div><div class="rounded-arka bg-black/15 p-3"><strong class="text-sm text-arka-text">Conductores de tu flota</strong><p class="mt-1 text-xs leading-5 text-arka-text-muted">Comparte solamente si quieres recomendar tu red.</p></div></div>
                </div>
            </section>

            <section>
                <div class="mb-4 flex items-end justify-between gap-4"><div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-arka-primary">Tu comunidad</p><h3 class="mt-1 text-xl font-semibold text-arka-text">Personas de tu círculo</h3></div></div>
                <div v-if="people.length" class="grid gap-4 lg:grid-cols-2">
                    <article v-for="person in people" :key="person.connection_public_id" class="rounded-arka border border-white/5 bg-arka-card p-4 sm:p-5">
                        <div class="flex items-start gap-3"><img v-if="person.avatar_url" :src="person.avatar_url" class="h-12 w-12 rounded-full object-cover" alt=""><span v-else class="grid h-12 w-12 place-items-center rounded-full bg-arka-primary/10 text-lg font-bold text-arka-primary">{{ person.name.charAt(0) }}</span><div class="min-w-0 flex-1"><strong class="block truncate text-base text-arka-text">{{ person.name }}</strong><span class="text-xs text-arka-text-muted">@{{ person.username }} · {{ person.role }}</span><div class="mt-2 flex flex-wrap gap-2"><span v-if="person.common_drivers" class="rounded-full bg-arka-primary/10 px-2.5 py-1 text-[11px] text-arka-primary">{{ person.common_drivers }} conductor(es) en común</span><span v-if="person.shares_fleet" class="rounded-full bg-white/5 px-2.5 py-1 text-[11px] text-arka-text-muted">Comparte {{ person.shared_drivers_count }} conductor(es)</span></div></div><div v-if="person.trust" class="text-right"><strong class="block text-xl text-arka-primary">{{ person.trust.score }}</strong><span class="text-[10px] text-arka-text-muted">{{ person.trust.level }}</span></div></div>
                        <button v-if="person.trust" class="mt-4 w-full border-t border-white/5 pt-3 text-left text-xs font-semibold text-arka-primary" @click="openPerson = openPerson === person.connection_public_id ? null : person.connection_public_id">{{ openPerson === person.connection_public_id ? 'Ocultar detalle' : 'Entender este índice' }}</button>
                        <div v-if="openPerson === person.connection_public_id" class="mt-3 grid grid-cols-2 gap-2"><div v-for="part in person.trust.components" :key="part.key" class="rounded-lg bg-black/15 p-2.5"><div class="flex justify-between text-[11px]"><span class="text-arka-text-muted">{{ part.label }}</span><strong class="text-arka-text">{{ part.points }}/{{ part.maximum }}</strong></div></div></div>
                        <div class="mt-4 border-t border-white/5 pt-4"><label class="block text-xs font-semibold text-arka-text">Cómo la conoces</label><input v-model="privacy[person.connection_public_id].relationship_label" class="mt-2 w-full rounded-lg border border-arka-text-muted/20 bg-arka-base px-3 py-2 text-sm text-arka-text" placeholder="Familia, amistad, trabajo…"><label class="mt-3 flex items-center justify-between gap-3 text-sm text-arka-text"><span><strong class="block text-xs">Compartir mi índice</strong><small class="text-[11px] text-arka-text-muted">Incluye calificación y actividad.</small></span><input v-model="privacy[person.connection_public_id].share_rating" type="checkbox" class="rounded border-arka-text-muted/30 bg-arka-base text-arka-primary focus:ring-arka-primary"></label><label v-if="canBuildFleet" class="mt-3 flex items-center justify-between gap-3 text-sm text-arka-text"><span><strong class="block text-xs">Compartir mis conductores</strong><small class="text-[11px] text-arka-text-muted">Podrá conocerlos y solicitar invitarlos.</small></span><input v-model="privacy[person.connection_public_id].share_fleet" type="checkbox" class="rounded border-arka-text-muted/30 bg-arka-base text-arka-primary focus:ring-arka-primary"></label><div class="mt-4 flex items-center justify-between"><button class="rounded-lg bg-arka-primary px-3 py-2 text-xs font-bold text-arka-base" @click="savePrivacy(person)">Guardar privacidad</button><button class="text-xs text-red-300" @click="removeConnection(person)">Quitar del círculo</button></div></div>
                    </article>
                </div>
                <div v-else class="rounded-arka border border-dashed border-arka-text-muted/20 bg-arka-card/60 p-8 text-center"><strong class="text-arka-text">Tu círculo empieza con una persona</strong><p class="mt-2 text-sm text-arka-text-muted">Busca a un familiar o amigo por su usuario o código de socio.</p></div>
            </section>

            <section v-if="canBuildFleet" class="rounded-arka border border-white/5 bg-arka-card p-4 sm:p-6">
                <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-arka-primary">Descubrimiento social</p><h3 class="mt-1 text-xl font-semibold text-arka-text">Conductores recomendados por tu círculo</h3><p class="mt-2 text-xs text-arka-text-muted">Solo aparecen desde flotas que sus dueños decidieron compartir contigo.</p></div><Link v-if="route().has('fleet.index')" :href="route('fleet.index')" class="text-xs font-semibold text-arka-primary">Ver mi flota →</Link></div>
                <div v-if="recommendedDrivers.length" class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <article v-for="driver in recommendedDrivers" :key="driver.driver_public_id" class="rounded-arka border border-white/5 bg-black/15 p-4"><div class="flex items-start gap-3"><img v-if="driver.avatar_url" :src="driver.avatar_url" class="h-11 w-11 rounded-full object-cover" alt=""><span v-else class="grid h-11 w-11 place-items-center rounded-full bg-arka-primary/10 font-bold text-arka-primary">{{ driver.name.charAt(0) }}</span><div class="min-w-0 flex-1"><strong class="block truncate text-sm text-arka-text">{{ driver.name }}</strong><span class="text-xs text-arka-text-muted">Socio #{{ driver.member_code }}</span></div><strong class="text-lg text-arka-primary">{{ driver.trust.score }}</strong></div><p class="mt-3 text-xs leading-5 text-arka-text-muted">Lo conocen <strong class="text-arka-text">{{ driver.recommended_by_count }}</strong> persona(s) de tu círculo<span v-if="driver.recommended_by.length">: {{ driver.recommended_by.join(', ') }}</span>.</p><button class="mt-4 w-full rounded-lg bg-arka-primary px-3 py-2.5 text-xs font-bold text-arka-base" @click="inviteDriver(driver)">Invitar a mi flota</button></article>
                </div>
                <div v-else class="mt-5 rounded-arka bg-black/15 p-5 text-center text-sm text-arka-text-muted">Cuando alguien comparta su flota contigo, aquí aparecerán sus conductores que aún no tienes.</div>
            </section>
        </main>
    </AuthenticatedLayout>
</template>

<style scoped>
.score-ring { width: 88px; height: 88px; flex: 0 0 auto; display: grid; place-items: center; border-radius: 50%; background: conic-gradient(#34d399 var(--score), rgba(255,255,255,.08) 0); position: relative; }
.score-ring::before { content: ''; position: absolute; inset: 7px; border-radius: inherit; background: #10231b; }
.score-ring > div { position: relative; z-index: 1; display: flex; align-items: baseline; }.score-ring strong { font-size: 1.6rem; color: #e7f4ee; }.score-ring small { color: #93ada2; font-size: .65rem; }
</style>
