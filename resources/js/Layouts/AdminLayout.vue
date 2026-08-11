<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link } from '@inertiajs/vue3';

// Layout compartido por todas las pantallas de /admin/* (sección 9.5-C):
// antes cada página repetía a mano una fila de links cruzados a las demás
// ("Indicadores · Planes · Alertas SOS..."), duplicada y fácil de olvidar
// actualizar. Ahora es una sola sub-nav persistente, coherente con el resto
// de la app (mismas pastillas redondeadas que la nav principal).
defineProps({
    title: { type: String, required: true },
});

const sections = [
    { route: 'admin.subscriptions.index', match: 'admin.subscriptions.*', label: 'Suscripciones' },
    { route: 'admin.plans.index', match: 'admin.plans.*', label: 'Planes' },
    { route: 'admin.plan-promotions.index', match: 'admin.plan-promotions.*', label: 'Promociones' },
    { route: 'admin.pricing.edit', match: 'admin.pricing.*', label: 'Tarifas' },
    { route: 'admin.metrics.index', match: 'admin.metrics.*', label: 'Indicadores' },
    { route: 'admin.drivers.index', match: 'admin.drivers.*', label: 'Conductores' },
    { route: 'admin.clients.index', match: 'admin.clients.*', label: 'Clientes' },
    { route: 'admin.driver-tiers.index', match: 'admin.driver-tiers.*', label: 'Medallas' },
    { route: 'admin.operations.index', match: 'admin.operations.*', label: 'Operaciones' },
    { route: 'admin.driver-verifications.index', match: 'admin.driver-verifications.*', label: 'Verificaciones' },
    { route: 'admin.rating-reasons.index', match: 'admin.rating-reasons.*', label: 'Motivos de calificación' },
    { route: 'admin.ad-banners.index', match: 'admin.ad-banners.*', label: 'Banners' },
    { route: 'admin.coupons.index', match: 'admin.coupons.*', label: 'Cupones' },
    { route: 'admin.sos-alerts.index', match: 'admin.sos-alerts.*', label: 'Alertas SOS' },
    { route: 'admin.locations.index', match: 'admin.locations.*', label: 'Zonas' },
    { route: 'admin.system.index', match: 'admin.system.*', label: 'Sistema' },
    // Roadmap de mejoras, secciones 8, 9, 11 y 12.
    { route: 'admin.integrations.whatsapp.edit', match: 'admin.integrations.*', label: 'Integraciones' },
    { route: 'admin.monitoring.index', match: 'admin.monitoring.*', label: 'Monitoreo' },
    { route: 'admin.faqs.index', match: 'admin.faqs.*', label: 'Preguntas frecuentes' },
    { route: 'admin.support-tickets.index', match: 'admin.support-tickets.*', label: 'Soporte' },
    { route: 'admin.platform-feedback.index', match: 'admin.platform-feedback.*', label: 'Opiniones' },
];
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

                <nav class="flex flex-wrap gap-1 bg-arka-base/60 rounded-full p-1 w-fit max-w-full overflow-x-auto">
                    <Link
                        v-for="section in sections"
                        :key="section.route"
                        :href="route(section.route)"
                        class="px-3 py-1.5 rounded-full text-xs sm:text-sm font-medium whitespace-nowrap transition"
                        :class="
                            route().current(section.match)
                                ? 'bg-arka-primary/15 text-arka-primary-bright'
                                : 'text-arka-text-muted hover:text-arka-text'
                        "
                    >
                        {{ section.label }}
                    </Link>
                </nav>
            </div>
        </template>

        <slot />
    </AuthenticatedLayout>
</template>
