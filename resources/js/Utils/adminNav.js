// Agrupación de las secciones del panel admin (pedido explícito del usuario,
// con captura de la sub-nav de escritorio: "28 enlaces sueltos... no
// transmite orden"). Única fuente de verdad, reusada en 3 lugares —
// Layouts/AdminLayout.vue (dropdowns de escritorio), Layouts/AuthenticatedLayout.vue
// (bottom sheet del FAB "+" en móvil, antes vacío de contenido para un admin)
// y Pages/Dashboard.vue (tarjetas del Inicio) — para no triplicar esta misma
// lista de 28 rutas en cada uno.
//
// Mismos `route`/`match`/`label` que ya vivían sueltos en AdminLayout.vue,
// repartidos entre 6 grupos temáticos. "Seguridad" queda con solo 2 ítems a
// propósito: mezclarla con otro grupo le restaría peso a algo que amerita
// estar aparte.
export const ADMIN_NAV_GROUPS = [
    {
        key: 'operacion',
        label: 'Operación',
        icon: 'route',
        items: [
            { route: 'admin.rides.index', match: 'admin.rides.*', label: 'Carreras' },
            { route: 'admin.user-locations.index', match: 'admin.user-locations.*', label: 'Registros por ubicación' },
            { route: 'admin.operations.index', match: 'admin.operations.*', label: 'Operaciones' },
            { route: 'admin.locations.index', match: 'admin.locations.*', label: 'Zonas' },
            { route: 'admin.monitoring.index', match: 'admin.monitoring.*', label: 'Monitoreo' },
        ],
    },
    {
        key: 'personas',
        label: 'Personas',
        icon: 'people',
        items: [
            { route: 'admin.cooperatives.index', match: 'admin.cooperatives.*', label: 'Cooperativas' },
            { route: 'admin.referrals.index', match: 'admin.referrals.*', label: 'Referidos' },
            { route: 'admin.drivers.index', match: 'admin.drivers.*', label: 'Conductores' },
            { route: 'admin.clients.index', match: 'admin.clients.*', label: 'Clientes' },
            { route: 'admin.driver-tiers.index', match: 'admin.driver-tiers.*', label: 'Medallas' },
            { route: 'admin.rating-reasons.index', match: 'admin.rating-reasons.*', label: 'Motivos de calificación' },
        ],
    },
    {
        key: 'comercial',
        label: 'Comercial',
        icon: 'tag',
        items: [
            { route: 'admin.subscriptions.index', match: 'admin.subscriptions.*', label: 'Suscripciones' },
            { route: 'admin.plans.index', match: 'admin.plans.*', label: 'Planes' },
            { route: 'admin.plan-promotions.index', match: 'admin.plan-promotions.*', label: 'Promociones' },
            { route: 'admin.pricing.edit', match: 'admin.pricing.*', label: 'Tarifas' },
            { route: 'admin.coupons.index', match: 'admin.coupons.*', label: 'Cupones' },
        ],
    },
    {
        key: 'seguridad',
        label: 'Seguridad',
        icon: 'shield',
        items: [
            { route: 'admin.driver-verifications.index', match: 'admin.driver-verifications.*', label: 'Verificaciones' },
            { route: 'admin.sos-alerts.index', match: 'admin.sos-alerts.*', label: 'Alertas SOS' },
        ],
    },
    {
        key: 'contenido',
        label: 'Contenido',
        icon: 'doc',
        items: [
            { route: 'admin.site.edit', match: 'admin.site.*', label: 'Sitio' },
            { route: 'admin.survey-metrics.index', match: 'admin.survey-metrics.*', label: 'Encuestas' },
            { route: 'admin.ad-banners.index', match: 'admin.ad-banners.*', label: 'Banners' },
            { route: 'admin.faqs.index', match: 'admin.faqs.*', label: 'Preguntas frecuentes' },
            { route: 'admin.platform-feedback.index', match: 'admin.platform-feedback.*', label: 'Opiniones' },
        ],
    },
    {
        key: 'sistema',
        label: 'Sistema',
        icon: 'gear',
        items: [
            { route: 'admin.metrics.index', match: 'admin.metrics.*', label: 'Indicadores' },
            { route: 'admin.system.index', match: 'admin.system.*', label: 'Sistema' },
            { route: 'admin.integrations.whatsapp.edit', match: 'admin.integrations.*', label: 'Integraciones' },
            { route: 'admin.whatsapp-inbox.index', match: 'admin.whatsapp-inbox.*', label: 'WhatsApp' },
            { route: 'admin.support-tickets.index', match: 'admin.support-tickets.*', label: 'Soporte' },
            { route: 'admin.chatbot.intents.index', match: 'admin.chatbot.*', label: 'Chatbot' },
        ],
    },
];
