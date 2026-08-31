<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TrustScoreBadge from '@/Components/TrustScoreBadge.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { confirmDialog } from '@/Utils/confirmDialog';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

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
const showSearch = ref(false);
const openPerson = ref(null);
const activeTab = ref(props.receivedRequests.length ? 'requests' : 'people');
const currentUser = computed(() => usePage().props.auth.user);
const requestCount = computed(() => props.receivedRequests.length + props.sentRequests.length);
const privacy = reactive(Object.fromEntries(props.people.map((person) => [person.connection_public_id, {
    relationship_label: person.relationship_label || '',
    share_fleet: person.my_privacy.share_fleet,
    share_rating: person.my_privacy.share_rating,
}])));

let searchTimer;
let searchRequest = 0;

watch(
    () => props.people,
    (people) => people.forEach((person) => {
        privacy[person.connection_public_id] ??= {
            relationship_label: person.relationship_label || '',
            share_fleet: person.my_privacy.share_fleet,
            share_rating: person.my_privacy.share_rating,
        };
    }),
    { deep: true },
);

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
        onSuccess: () => {
            search.value = '';
            searchResults.value = [];
            showSearch.value = false;
            activeTab.value = 'requests';
        },
    });
}

function respond(request, action) {
    router.post(route('trust-circle.respond', request.connection_public_id), { action }, {
        preserveScroll: true,
        onSuccess: () => {
            if (action === 'accept') activeTab.value = 'people';
        },
    });
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

function openConnection(person) {
    activeTab.value = 'people';
    openPerson.value = openPerson.value === person.connection_public_id ? null : person.connection_public_id;
}

function scoreStyle(score) {
    return { '--score': `${Math.max(0, Math.min(100, score)) * 3.6}deg` };
}
</script>

<template>
    <Head title="Mi círculo" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-arka-primary">Red privada</p>
                    <h2 class="mt-0.5 text-xl font-semibold text-arka-text">Mi círculo</h2>
                </div>
                <button type="button" class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-arka-primary px-4 text-xs font-bold text-arka-base" @click="activeTab = 'people'; showSearch = !showSearch">
                    <svg class="h-4 w-4 fill-none stroke-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round" /></svg>
                    Agregar
                </button>
            </div>
        </template>

        <main class="mx-auto max-w-3xl space-y-4 px-3 py-4 pb-24 sm:px-6 sm:py-6">
            <section class="rounded-2xl border border-white/5 bg-arka-card p-4 sm:p-5">
                <div class="flex items-center gap-4">
                    <UserAvatar :user="currentUser" size-class="h-16 w-16 text-xl shrink-0" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h1 class="truncate text-lg font-bold text-arka-text">{{ currentUser.full_name || currentUser.name }}</h1>
                                <p class="truncate text-xs text-arka-text-muted">@{{ currentUser.username }} · Mi red de confianza</p>
                            </div>
                            <button class="score-mini" :style="scoreStyle(summary.own_trust.score)" type="button" aria-label="Ver mi índice" @click="openPerson = openPerson === 'own' ? null : 'own'"><span>{{ summary.own_trust.score }}%</span></button>
                        </div>
                        <div class="profile-stats mt-4 grid grid-cols-3 divide-x divide-white/5 text-center">
                            <button type="button" @click="activeTab = 'people'"><strong>{{ summary.people }}</strong><span>Personas</span></button>
                            <button type="button" @click="activeTab = 'requests'"><strong>{{ receivedRequests.length }}</strong><span>Pendientes</span></button>
                            <button type="button" :disabled="!canBuildFleet" @click="canBuildFleet && (activeTab = 'drivers')"><strong>{{ recommendedDrivers.length }}</strong><span>Sugeridos</span></button>
                        </div>
                    </div>
                </div>
                <div v-if="openPerson === 'own'" class="mt-4 rounded-xl bg-arka-base/55 p-3">
                    <div class="flex items-center justify-between gap-3"><div><p class="text-xs font-semibold text-arka-text">Tu índice: {{ summary.own_trust.level }}</p><p class="mt-0.5 text-[11px] text-arka-text-muted">Actividad, cumplimiento y relaciones reales.</p></div><Link :href="route('profile.edit')" class="shrink-0 text-xs font-semibold text-arka-primary">Ver perfil</Link></div>
                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4"><div v-for="part in summary.own_trust.components" :key="part.key" class="rounded-lg bg-black/15 p-2.5"><div class="flex justify-between gap-2 text-[10px]"><span class="truncate text-arka-text-muted">{{ part.label }}</span><strong class="text-arka-text">{{ part.points }}/{{ part.maximum }}</strong></div></div></div>
                </div>
            </section>

            <section v-if="people.length" aria-label="Personas de tu círculo" class="overflow-hidden rounded-2xl border border-white/5 bg-arka-card py-3">
                <div class="circle-strip flex gap-3 overflow-x-auto px-4 pb-1">
                    <button v-for="person in people" :key="person.connection_public_id" type="button" class="w-[66px] shrink-0 text-center" @click="openConnection(person)">
                        <span class="mx-auto block rounded-full bg-gradient-to-br from-arka-primary to-lime-400 p-[2px]"><span class="block rounded-full bg-arka-card p-[2px]"><img v-if="person.avatar_url" :src="person.avatar_url" class="h-12 w-12 rounded-full object-cover" alt=""><span v-else class="grid h-12 w-12 place-items-center rounded-full bg-arka-base font-bold text-arka-primary">{{ person.name.charAt(0) }}</span></span></span>
                        <span class="mt-1.5 block truncate text-[10px] text-arka-text">{{ person.name.split(' ')[0] }}</span>
                    </button>
                </div>
            </section>

            <nav class="grid rounded-2xl border border-white/5 bg-arka-card p-1" :class="canBuildFleet ? 'grid-cols-3' : 'grid-cols-2'" aria-label="Secciones del círculo">
                <button type="button" class="social-tab" :class="{ active: activeTab === 'people' }" @click="activeTab = 'people'"><svg viewBox="0 0 24 24"><path d="M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-1a3.5 3.5 0 1 0 0-7M2 21a6 6 0 0 1 12 0m1-7a5 5 0 0 1 7 4.6" /></svg><span>Personas</span></button>
                <button type="button" class="social-tab" :class="{ active: activeTab === 'requests' }" @click="activeTab = 'requests'"><svg viewBox="0 0 24 24"><path d="M5 4h14v12H9l-4 4V4Z" /></svg><span>Solicitudes</span><i v-if="requestCount">{{ requestCount }}</i></button>
                <button v-if="canBuildFleet" type="button" class="social-tab" :class="{ active: activeTab === 'drivers' }" @click="activeTab = 'drivers'"><svg viewBox="0 0 24 24"><path d="M4 15.5 6 9h12l2 6.5M6 9l2-4h8l2 4M5 15h14v4H5v-4Zm2 1.5h.01m10 0h.01" /></svg><span>Conductores</span></button>
            </nav>

            <section v-if="activeTab === 'people'" class="space-y-3">
                <div v-if="showSearch" class="rounded-2xl border border-arka-primary/20 bg-arka-card p-4">
                    <div class="flex items-center justify-between gap-3"><div><h3 class="text-sm font-semibold text-arka-text">Agregar a alguien que conoces</h3><p class="mt-1 text-xs text-arka-text-muted">Busca por nombre, usuario o código. No mostramos datos de contacto.</p></div><button type="button" class="p-2 text-arka-text-muted" aria-label="Cerrar búsqueda" @click="showSearch = false"><svg class="h-5 w-5 fill-none stroke-current" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18" stroke-width="1.8" stroke-linecap="round" /></svg></button></div>
                    <div class="relative mt-3"><svg class="pointer-events-none absolute left-3 top-3.5 h-5 w-5 fill-none stroke-arka-text-muted" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" stroke-width="1.7"/><path d="m16 16 4 4" stroke-width="1.7" stroke-linecap="round"/></svg><input v-model="search" autofocus class="w-full rounded-xl border border-arka-text-muted/20 bg-arka-base py-3 pl-10 pr-20 text-sm text-arka-text placeholder:text-arka-text-muted/70 focus:border-arka-primary focus:ring-arka-primary" placeholder="Nombre, @usuario o código"><span v-if="searching" class="absolute right-3 top-3.5 text-xs text-arka-primary">Buscando…</span></div>
                    <p v-if="searchError" class="mt-2 text-xs text-red-300">{{ searchError }}</p>
                    <div v-if="searchResults.length" class="mt-3 divide-y divide-white/5"><article v-for="person in searchResults" :key="person.user_public_id" class="flex items-center gap-3 py-3"><UserAvatar :user="person" size-class="h-10 w-10 text-sm shrink-0" /><div class="min-w-0 flex-1"><strong class="block truncate text-sm text-arka-text">{{ person.name }}</strong><span class="text-xs text-arka-text-muted">@{{ person.username }} · {{ person.role }}</span></div><span v-if="person.connection_status" class="text-xs text-arka-text-muted">{{ person.connection_status === 'accepted' ? 'Conectado' : 'Pendiente' }}</span><button v-else class="rounded-lg bg-arka-primary px-3 py-2 text-xs font-bold text-arka-base" @click="sendRequest(person)">Conectar</button></article></div>
                </div>

                <button v-else type="button" class="flex w-full items-center gap-3 rounded-2xl border border-dashed border-arka-primary/25 bg-arka-card/70 p-4 text-left" @click="showSearch = true"><span class="grid h-10 w-10 place-items-center rounded-full bg-arka-primary/10 text-arka-primary"><svg class="h-5 w-5 fill-none stroke-current" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round" /></svg></span><span><strong class="block text-sm text-arka-text">Encuentra a alguien que conoces</strong><small class="mt-0.5 block text-xs text-arka-text-muted">Familia, amistades o personas de confianza.</small></span></button>

                <article v-for="person in people" :key="person.connection_public_id" class="overflow-hidden rounded-2xl border border-white/5 bg-arka-card">
                    <button type="button" class="flex w-full items-center gap-3 p-4 text-left" :aria-expanded="openPerson === person.connection_public_id" @click="openConnection(person)">
                        <UserAvatar :user="person" size-class="h-12 w-12 text-base shrink-0" />
                        <div class="min-w-0 flex-1"><strong class="block truncate text-sm text-arka-text">{{ person.name }}</strong><span class="text-xs text-arka-text-muted">@{{ person.username }} · {{ person.relationship_label || person.role }}</span><div class="mt-1.5 flex flex-wrap gap-1.5"><TrustScoreBadge :trust="person.trust" compact /><span v-if="person.common_drivers" class="rounded-full bg-white/5 px-2 py-0.5 text-[10px] text-arka-text-muted">{{ person.common_drivers }} conductor{{ person.common_drivers === 1 ? '' : 'es' }} en común</span></div></div>
                        <svg class="h-5 w-5 shrink-0 fill-none stroke-arka-text-muted transition-transform" :class="{ 'rotate-180': openPerson === person.connection_public_id }" viewBox="0 0 24 24"><path d="m7 9 5 5 5-5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </button>
                    <div v-if="openPerson === person.connection_public_id" class="border-t border-white/5 p-4">
                        <div v-if="person.trust" class="grid grid-cols-2 gap-2 sm:grid-cols-4"><div v-for="part in person.trust.components" :key="part.key" class="rounded-lg bg-arka-base/50 p-2.5"><div class="flex justify-between gap-2 text-[10px]"><span class="truncate text-arka-text-muted">{{ part.label }}</span><strong class="text-arka-text">{{ part.points }}/{{ part.maximum }}</strong></div></div></div>
                        <div class="mt-4 rounded-xl bg-arka-base/45 p-3"><div class="flex items-center justify-between gap-3"><div><strong class="block text-xs text-arka-text">Compartir mi índice</strong><small class="text-[11px] text-arka-text-muted">Muestra reputación y actividad.</small></div><input v-model="privacy[person.connection_public_id].share_rating" type="checkbox" class="rounded border-arka-text-muted/30 bg-arka-base text-arka-primary focus:ring-arka-primary"></div><div v-if="canBuildFleet" class="mt-3 flex items-center justify-between gap-3"><div><strong class="block text-xs text-arka-text">Compartir mis conductores</strong><small class="text-[11px] text-arka-text-muted">Podrá descubrirlos y pedir invitarlos.</small></div><input v-model="privacy[person.connection_public_id].share_fleet" type="checkbox" class="rounded border-arka-text-muted/30 bg-arka-base text-arka-primary focus:ring-arka-primary"></div><label class="mt-3 block text-xs font-semibold text-arka-text">Cómo la conoces<input v-model="privacy[person.connection_public_id].relationship_label" class="mt-1.5 w-full rounded-lg border border-arka-text-muted/20 bg-arka-base px-3 py-2 text-sm text-arka-text" placeholder="Familia, amistad, trabajo…"></label></div>
                        <div class="mt-3 flex items-center justify-between gap-3"><button class="rounded-lg bg-arka-primary px-3 py-2 text-xs font-bold text-arka-base" @click="savePrivacy(person)">Guardar</button><button class="text-xs text-red-300" @click="removeConnection(person)">Quitar del círculo</button></div>
                    </div>
                </article>

                <div v-if="!people.length" class="rounded-2xl border border-dashed border-white/10 bg-arka-card p-8 text-center"><span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-arka-primary/10 text-arka-primary"><svg class="h-6 w-6 fill-none stroke-current" viewBox="0 0 24 24"><path d="M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-1a3.5 3.5 0 1 0 0-7M2 21a6 6 0 0 1 12 0m1-7a5 5 0 0 1 7 4.6" stroke-width="1.7" stroke-linecap="round" /></svg></span><strong class="mt-3 block text-sm text-arka-text">Tu círculo empieza con una persona</strong><p class="mt-1 text-xs text-arka-text-muted">Agrega a alguien que realmente conoces.</p></div>

                <details class="help-panel overflow-hidden rounded-2xl border border-white/5 bg-arka-card"><summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4"><div><strong class="text-sm text-arka-text">¿Cómo funciona?</strong><p class="mt-0.5 text-xs text-arka-text-muted">Una guía corta sobre conexiones y privacidad.</p></div><svg class="help-chevron h-5 w-5 fill-none stroke-arka-text-muted transition-transform" viewBox="0 0 24 24"><path d="m7 9 5 5 5-5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg></summary><div class="border-t border-white/5 p-4"><div class="grid gap-3 sm:grid-cols-2"><div class="flow-step"><span>1</span><p><strong>Busca a alguien</strong> por nombre, usuario o código.</p></div><div class="flow-step"><span>2</span><p><strong>Debe aceptar.</strong> Nadie entra automáticamente.</p></div><div class="flow-step"><span>3</span><p><strong>Descubre coincidencias</strong> y conductores compartidos.</p></div><div class="flow-step"><span>4</span><p><strong>Tú controlas</strong> el índice y la flota que compartes.</p></div></div><p class="mt-3 text-[11px] leading-5 text-arka-text-muted">El índice resume señales internas de Arka01. No certifica identidad ni garantiza seguridad.</p></div></details>
            </section>

            <section v-else-if="activeTab === 'requests'" class="space-y-3">
                <div v-if="receivedRequests.length"><div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-semibold text-arka-text">Quieren conectar contigo</h3><span class="rounded-full bg-arka-primary/10 px-2 py-1 text-[10px] font-bold text-arka-primary">{{ receivedRequests.length }} nuevas</span></div><article v-for="request in receivedRequests" :key="request.connection_public_id" class="rounded-2xl border border-arka-primary/15 bg-arka-card p-4"><div class="flex items-start gap-3"><UserAvatar :user="request" size-class="h-12 w-12 text-base shrink-0" /><div class="min-w-0 flex-1"><strong class="block truncate text-sm text-arka-text">{{ request.name }}</strong><span class="text-xs text-arka-text-muted">@{{ request.username }} · Socio #{{ request.member_code }}</span><div class="mt-2 flex flex-wrap gap-1.5"><span class="rounded-full bg-white/5 px-2 py-0.5 text-[10px] text-arka-text-muted">{{ request.role }}</span><span v-if="request.relationship_label" class="rounded-full bg-white/5 px-2 py-0.5 text-[10px] text-arka-text-muted">Te conoce como {{ request.relationship_label }}</span><TrustScoreBadge :trust="request.trust" compact /></div></div></div><div class="mt-4 grid grid-cols-3 gap-2"><Link :href="route('profiles.show', request.user_public_id)" class="grid min-h-10 place-items-center rounded-xl border border-arka-primary/25 text-xs font-semibold text-arka-primary">Ver perfil</Link><button class="min-h-10 rounded-xl bg-arka-primary text-xs font-bold text-arka-base" @click="respond(request, 'accept')">Aceptar</button><button class="min-h-10 rounded-xl bg-white/5 text-xs font-semibold text-arka-text-muted" @click="respond(request, 'reject')">Ahora no</button></div></article></div>
                <div v-if="sentRequests.length"><h3 class="mb-3 text-sm font-semibold text-arka-text">Solicitudes enviadas</h3><div class="divide-y divide-white/5 rounded-2xl border border-white/5 bg-arka-card px-4"><div v-for="request in sentRequests" :key="request.connection_public_id" class="flex items-center gap-3 py-3"><UserAvatar :user="request" size-class="h-10 w-10 text-sm shrink-0" /><div class="min-w-0 flex-1"><strong class="block truncate text-sm text-arka-text">{{ request.name }}</strong><span class="text-xs text-arka-text-muted">@{{ request.username }}</span></div><span class="rounded-full bg-white/5 px-2 py-1 text-[10px] text-arka-text-muted">Pendiente</span></div></div></div>
                <div v-if="!requestCount" class="rounded-2xl border border-dashed border-white/10 bg-arka-card p-8 text-center"><strong class="text-sm text-arka-text">No tienes solicitudes pendientes</strong><p class="mt-1 text-xs text-arka-text-muted">Cuando alguien quiera conectar, aparecerá aquí.</p><button class="mt-4 rounded-xl bg-arka-primary px-4 py-2.5 text-xs font-bold text-arka-base" @click="activeTab = 'people'; showSearch = true">Buscar personas</button></div>
            </section>

            <section v-else class="space-y-3">
                <div class="rounded-2xl border border-white/5 bg-arka-card p-4"><h3 class="text-sm font-semibold text-arka-text">Conductores que conoce tu círculo</h3><p class="mt-1 text-xs leading-5 text-arka-text-muted">Solo aparecen desde flotas que sus dueños decidieron compartir contigo.</p></div>
                <article v-for="driver in recommendedDrivers" :key="driver.driver_public_id" class="rounded-2xl border border-white/5 bg-arka-card p-4"><div class="flex items-center gap-3"><UserAvatar :user="driver" size-class="h-12 w-12 text-base shrink-0" /><div class="min-w-0 flex-1"><strong class="block truncate text-sm text-arka-text">{{ driver.name }}</strong><span class="text-xs text-arka-text-muted">Socio #{{ driver.member_code }}</span><p class="mt-1 text-xs text-arka-primary">Lo conocen {{ driver.recommended_by_count }} persona{{ driver.recommended_by_count === 1 ? '' : 's' }} de tu círculo</p></div><TrustScoreBadge :trust="driver.trust" compact /></div><p v-if="driver.recommended_by.length" class="mt-3 text-xs text-arka-text-muted">Conocido por {{ driver.recommended_by.join(', ') }}.</p><div class="mt-4 grid grid-cols-2 gap-2"><Link :href="route('profiles.show', driver.driver_public_id)" class="grid min-h-10 place-items-center rounded-xl border border-arka-primary/25 text-xs font-semibold text-arka-primary">Ver perfil</Link><button class="min-h-10 rounded-xl bg-arka-primary text-xs font-bold text-arka-base" @click="inviteDriver(driver)">Invitar a mi flota</button></div></article>
                <div v-if="!recommendedDrivers.length" class="rounded-2xl border border-dashed border-white/10 bg-arka-card p-8 text-center"><strong class="text-sm text-arka-text">Todavía no hay conductores compartidos</strong><p class="mt-1 text-xs leading-5 text-arka-text-muted">Cuando alguien de tu círculo comparta su flota, sus conductores aparecerán aquí.</p></div>
            </section>
        </main>
    </AuthenticatedLayout>
</template>

<style scoped>
.score-mini { width: 46px; height: 46px; flex: 0 0 auto; display: grid; place-items: center; border-radius: 50%; background: conic-gradient(#34d399 var(--score), rgba(255,255,255,.08) 0); position: relative; }
.score-mini::before { content: ''; position: absolute; inset: 4px; border-radius: inherit; background: #10231b; }
.score-mini span { position: relative; z-index: 1; color: #e7f4ee; font-size: .78rem; font-weight: 800; }
.profile-stats strong { display: block; color: #e7f4ee; font-size: .9rem; }.profile-stats span { display: block; margin-top: .12rem; color: #93ada2; font-size: .58rem; }
.circle-strip { scrollbar-width: none; }.circle-strip::-webkit-scrollbar { display: none; }
.social-tab { min-height: 3rem; position: relative; display: flex; align-items: center; justify-content: center; gap: .4rem; border-radius: .8rem; color: #93ada2; font-size: .7rem; font-weight: 700; transition: .18s ease; }
.social-tab svg { width: 1rem; height: 1rem; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.social-tab.active { background: rgba(52,211,153,.12); color: #34d399; }.social-tab i { min-width: 1rem; height: 1rem; display: grid; place-items: center; border-radius: 999px; background: #34d399; color: #07150f; font-size: .55rem; font-style: normal; }
.social-tab:focus-visible { outline: 2px solid #34d399; outline-offset: 1px; }
.help-panel[open] .help-chevron { transform: rotate(180deg); }
.flow-step { display: flex; align-items: flex-start; gap: .65rem; border-radius: .75rem; padding: .7rem; background: rgba(0,0,0,.14); color: #93ada2; font-size: .7rem; line-height: 1.5; }.flow-step > span { width: 1.5rem; height: 1.5rem; flex: 0 0 auto; display: grid; place-items: center; border-radius: 50%; background: rgba(52,211,153,.12); color: #34d399; font-size: .65rem; font-weight: 800; }.flow-step strong { color: #e7f4ee; }
</style>
