<script setup>
import AdminNavIcon from '@/Components/AdminNavIcon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ADMIN_NAV_GROUPS } from '@/Utils/adminNav';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    title: { type: String, required: true },
});

const page = usePage();

const driverAttentionCount = computed(() =>
    (page.props.auth.notificationSummary?.items ?? [])
        .filter((item) => item.key === 'admin-driver-registrations' || item.key === 'admin-driver-verifications')
        .reduce((total, item) => total + Number(item.count || 0), 0)
);

function groupIsActive(group) {
    return group.items.some((item) => route().current(item.match));
}

function attentionFor(item) {
    return item.attention === 'drivers' ? driverAttentionCount.value : 0;
}

const currentGroup = computed(() => ADMIN_NAV_GROUPS.find(groupIsActive));
const currentItem = computed(() => currentGroup.value?.items.find((item) => route().current(item.match)));
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-arka-warning/12 text-arka-warning">
                    <span class="h-5 w-5">
                        <AdminNavIcon :icon="currentGroup?.icon ?? 'shield'" />
                    </span>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-xs font-semibold uppercase tracking-[0.14em] text-arka-text-muted">
                        Panel admin<span v-if="currentGroup"> · {{ currentGroup.label }}</span>
                    </p>
                    <h2 class="truncate text-xl font-semibold leading-tight text-arka-text">{{ title }}</h2>
                    <p v-if="currentItem && currentItem.label !== title" class="mt-0.5 truncate text-xs text-arka-text-muted">
                        {{ currentItem.label }}
                    </p>
                </div>
            </div>
        </template>

        <div class="mx-auto w-full max-w-[1600px] lg:grid lg:grid-cols-[18rem_minmax(0,1fr)] lg:items-start">
            <!-- Escritorio: navegación vertical persistente. El operador ve
                 siempre dónde está y no tiene que recordar en qué dropdown
                 estaba cada herramienta. -->
            <aside class="hidden px-4 py-8 lg:block xl:px-6" aria-label="Navegación administrativa">
                <nav class="sticky top-4 rounded-2xl border border-arka-text-muted/10 bg-arka-card p-3 shadow-sm">
                    <Link
                        :href="route('dashboard')"
                        class="mb-3 flex items-center gap-3 rounded-xl border border-arka-warning/15 bg-arka-warning/5 px-3 py-3 transition hover:border-arka-warning/35"
                    >
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-arka-warning/15 text-arka-warning">
                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4 11 8-7 8 7v8a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1v-8Z" />
                            </svg>
                        </span>
                        <span>
                            <span class="block text-sm font-semibold text-arka-text">Inicio administrativo</span>
                            <span class="block text-[11px] text-arka-text-muted">Pendientes e indicadores</span>
                        </span>
                    </Link>

                    <details v-for="group in ADMIN_NAV_GROUPS" :key="group.key" :open="groupIsActive(group)" class="group border-t border-arka-text-muted/10 py-1 first:border-0">
                        <summary class="flex cursor-pointer list-none items-start gap-2 rounded-lg px-2 py-2.5 hover:bg-arka-base">
                            <span class="mt-0.5 h-4 w-4 shrink-0 text-arka-text-muted"><AdminNavIcon :icon="group.icon" /></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <h3 class="text-xs font-bold uppercase tracking-[0.1em] text-arka-text">{{ group.label }}</h3>
                                    <span
                                        v-if="group.key === 'conductores' && driverAttentionCount > 0"
                                        class="inline-flex min-w-5 items-center justify-center rounded-full bg-arka-primary px-1.5 py-0.5 text-[10px] font-bold text-arka-base"
                                    >
                                        {{ driverAttentionCount }}
                                    </span>
                                </div>
                                <p class="mt-0.5 text-[10px] leading-4 text-arka-text-muted">{{ group.description }}</p>
                            </div>
                            <svg class="mt-1 h-3.5 w-3.5 shrink-0 text-arka-text-muted transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m7 9 5 5 5-5" /></svg>
                        </summary>

                        <div class="mb-2 space-y-0.5 pl-5">
                            <Link
                                v-for="item in group.items"
                                :key="item.route"
                                :href="route(item.route)"
                                class="flex min-h-10 items-center justify-between gap-2 rounded-lg border-l-2 px-3 py-2 text-sm transition"
                                :class="
                                    route().current(item.match)
                                        ? 'border-arka-primary bg-arka-primary/10 font-semibold text-arka-primary-bright'
                                        : 'border-transparent text-arka-text-muted hover:bg-arka-base hover:text-arka-text'
                                "
                                :aria-current="route().current(item.match) ? 'page' : undefined"
                            >
                                <span>{{ item.label }}</span>
                                <span
                                    v-if="attentionFor(item) > 0"
                                    class="inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-arka-primary px-1.5 py-0.5 text-[10px] font-bold text-arka-base"
                                >
                                    {{ attentionFor(item) }}
                                </span>
                            </Link>
                        </div>
                    </details>
                </nav>
            </aside>

            <div class="min-w-0">
                <!-- Móvil/tablet: un único selector vertical cerrado ocupa
                     poco espacio; al abrirlo muestra las mismas áreas del
                     menú lateral, no siete barras antes del contenido. -->
                <nav class="px-4 pt-4 lg:hidden" aria-label="Navegación administrativa móvil">
                    <details class="overflow-hidden rounded-xl border border-arka-text-muted/10 bg-arka-card">
                        <summary class="flex cursor-pointer list-none items-center gap-3 px-3 py-3">
                            <span class="h-5 w-5 shrink-0 text-arka-primary"><AdminNavIcon :icon="currentGroup?.icon ?? 'shield'" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-arka-text">Menú administrativo</span>
                                <span class="block truncate text-[11px] text-arka-text-muted">{{ currentGroup?.label ?? 'Todas las áreas' }}</span>
                            </span>
                            <span v-if="driverAttentionCount > 0" class="rounded-full bg-arka-primary px-2 py-0.5 text-[10px] font-bold text-arka-base">
                                {{ driverAttentionCount }}
                            </span>
                            <svg class="h-4 w-4 shrink-0 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m7 9 5 5 5-5" />
                            </svg>
                        </summary>
                        <div class="max-h-[65vh] space-y-3 overflow-y-auto border-t border-arka-text-muted/10 p-2">
                            <section v-for="group in ADMIN_NAV_GROUPS" :key="group.key">
                                <div class="flex items-center gap-2 px-2 pb-1 pt-1">
                                    <span class="h-4 w-4 shrink-0 text-arka-text-muted"><AdminNavIcon :icon="group.icon" /></span>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-[0.08em] text-arka-text">{{ group.label }}</p>
                                        <p class="truncate text-[10px] text-arka-text-muted">{{ group.description }}</p>
                                    </div>
                                </div>
                                <div class="grid gap-1 sm:grid-cols-2">
                                    <Link
                                        v-for="item in group.items"
                                        :key="item.route"
                                        :href="route(item.route)"
                                        class="flex min-h-11 items-center justify-between rounded-lg px-3 py-2 text-sm"
                                        :class="route().current(item.match) ? 'bg-arka-primary/10 font-semibold text-arka-primary-bright' : 'text-arka-text-muted hover:bg-arka-base'"
                                    >
                                        <span>{{ item.label }}</span>
                                        <span v-if="attentionFor(item) > 0" class="rounded-full bg-arka-primary px-2 py-0.5 text-[10px] font-bold text-arka-base">
                                            {{ attentionFor(item) }}
                                        </span>
                                    </Link>
                                </div>
                            </section>
                        </div>
                    </details>
                </nav>

                <main class="min-w-0">
                    <slot />
                </main>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
