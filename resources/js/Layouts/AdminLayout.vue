<script setup>
import AdminNavIcon from '@/Components/AdminNavIcon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Dropdown from '@/Components/Dropdown.vue';
import { ADMIN_NAV_GROUPS } from '@/Utils/adminNav';
import { Link } from '@inertiajs/vue3';

// Layout compartido por todas las pantallas de /admin/* (sección 9.5-C):
// antes cada página repetía a mano una fila de links cruzados a las demás
// ("Indicadores · Planes · Alertas SOS..."), duplicada y fácil de olvidar
// actualizar. Ahora es una sola sub-nav persistente, coherente con el resto
// de la app (mismas pastillas redondeadas que la nav principal).
//
// Pedido explícito del usuario (con captura: "28 enlaces sueltos... no
// transmite orden"): la fila plana de siempre pasa a 6 dropdowns agrupados
// por tema — ver Utils/adminNav.js, la misma agrupación que usan el bottom
// sheet del FAB en móvil (AuthenticatedLayout.vue) y las tarjetas del Inicio
// (Dashboard.vue), para no repetir esta lista de 28 rutas en 3 lugares.
defineProps({
    title: { type: String, required: true },
});

function groupIsActive(group) {
    return group.items.some((item) => route().current(item.match));
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="space-y-4">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-arka-warning shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                    </svg>
                    <p class="text-xs uppercase tracking-wide text-arka-text-muted">Panel admin</p>
                </div>
                <h2 class="font-semibold text-xl text-arka-text leading-tight">{{ title }}</h2>

                <nav class="flex flex-wrap gap-1.5">
                    <Dropdown v-for="group in ADMIN_NAV_GROUPS" :key="group.key" align="left" width="56">
                        <template #trigger>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs sm:text-sm font-medium transition"
                                :class="
                                    groupIsActive(group)
                                        ? 'bg-arka-primary/15 text-arka-primary-bright'
                                        : 'bg-arka-base/60 text-arka-text-muted hover:text-arka-text'
                                "
                            >
                                <span class="h-4 w-4 shrink-0"><AdminNavIcon :icon="group.icon" /></span>
                                {{ group.label }}
                                <svg class="h-3 w-3 shrink-0 opacity-60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                        </template>
                        <template #content>
                            <Link
                                v-for="item in group.items"
                                :key="item.route"
                                :href="route(item.route)"
                                class="block px-4 py-2 text-sm transition"
                                :class="route().current(item.match) ? 'text-arka-primary-bright bg-arka-base' : 'text-arka-text hover:bg-arka-base'"
                            >
                                {{ item.label }}
                            </Link>
                        </template>
                    </Dropdown>
                </nav>
            </div>
        </template>

        <slot />
    </AuthenticatedLayout>
</template>
