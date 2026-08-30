// Arquitectura de navegación del panel administrativo.
//
// Es la única fuente de verdad para el menú lateral de AdminLayout, el
// acceso móvil desde el botón central y los accesos del Inicio. Los grupos
// siguen tareas reales: un conductor nuevo se registra, completa el
// expediente y se verifica dentro de una misma sección; la operación diaria
// no se mezcla con configuración, monitoreo ni contenido público.
export const ADMIN_NAV_GROUPS = [
    {
        key: 'conductores',
        label: 'Conductores',
        description: 'Registro, aprobación y red de transporte',
        icon: 'shield',
        items: [
            { route: 'admin.driver-verifications.index', match: 'admin.driver-verifications.*', label: 'Altas y verificaciones', attention: 'drivers' },
            { route: 'admin.drivers.index', match: 'admin.drivers.*', label: 'Todos los conductores' },
            { route: 'admin.driver-tiers.index', match: 'admin.driver-tiers.*', label: 'Categorías y medallas' },
        ],
    },
    {
        key: 'cooperativas',
        label: 'Cooperativas',
        description: 'Organizaciones, documentos y conductores asociados',
        icon: 'building',
        items: [
            { route: 'admin.cooperatives.index', match: 'admin.cooperatives.*', label: 'Gestión de cooperativas' },
        ],
    },
    {
        key: 'clientes',
        label: 'Clientes y comunidad',
        description: 'Personas, crecimiento y procedencia',
        icon: 'people',
        items: [
            { route: 'admin.clients.index', match: 'admin.clients.*', label: 'Todos los clientes' },
            { route: 'admin.user-locations.index', match: 'admin.user-locations.*', label: 'Registros por ubicación' },
            { route: 'admin.referrals.index', match: 'admin.referrals.*', label: 'Referidos' },
        ],
    },
    {
        key: 'operacion',
        label: 'Operación diaria',
        description: 'Carreras, demanda y seguridad en curso',
        icon: 'route',
        items: [
            { route: 'admin.live-operations.index', match: 'admin.live-operations.*', label: 'Centro de operaciones en vivo' },
            { route: 'admin.rides.index', match: 'admin.rides.*', label: 'Historial de carreras' },
            { route: 'admin.operations.index', match: 'admin.operations.*', label: 'Demanda y cobertura' },
            { route: 'admin.sos-alerts.index', match: 'admin.sos-alerts.*', label: 'Alertas SOS' },
        ],
    },
    {
        key: 'comercial',
        label: 'Planes y facturación',
        description: 'Suscripciones, precios y promociones',
        icon: 'tag',
        items: [
            { route: 'admin.subscriptions.index', match: 'admin.subscriptions.*', label: 'Suscripciones' },
            { route: 'admin.plans.index', match: 'admin.plans.*', label: 'Planes' },
            { route: 'admin.pricing.edit', match: 'admin.pricing.*', label: 'Tarifas' },
            { route: 'admin.plan-promotions.index', match: 'admin.plan-promotions.*', label: 'Promociones' },
            { route: 'admin.plan-coupons.index', match: 'admin.plan-coupons.*', label: 'Cupones de planes' },
            { route: 'admin.coupons.index', match: 'admin.coupons.*', label: 'Beneficios para usuarios' },
        ],
    },
    {
        key: 'comunicacion',
        label: 'Atención y comunicación',
        description: 'WhatsApp, chatbot y solicitudes de ayuda',
        icon: 'message',
        items: [
            { route: 'admin.whatsapp-inbox.index', match: 'admin.whatsapp-inbox.*', label: 'Bandeja de WhatsApp' },
            { route: 'admin.chatbot.intents.index', match: 'admin.chatbot.*', label: 'Chatbot' },
            { route: 'admin.support-tickets.index', match: 'admin.support-tickets.*', label: 'Casos de soporte' },
            { route: 'admin.survey-metrics.index', match: 'admin.survey-metrics.*', label: 'Encuestas' },
            { route: 'admin.platform-feedback.index', match: 'admin.platform-feedback.*', label: 'Opiniones' },
        ],
    },
    {
        key: 'contenido',
        label: 'Contenido público',
        description: 'Portada, campañas y ayuda visible',
        icon: 'doc',
        items: [
            { route: 'admin.site.edit', match: 'admin.site.*', label: 'Sitio y portada' },
            { route: 'admin.ad-banners.index', match: 'admin.ad-banners.*', label: 'Banners' },
            { route: 'admin.faqs.index', match: 'admin.faqs.*', label: 'Preguntas frecuentes' },
        ],
    },
    {
        key: 'sistema',
        label: 'Sistema',
        description: 'Configuración, reglas e integraciones',
        icon: 'gear',
        items: [
            { route: 'admin.system.index', match: 'admin.system.*', label: 'Configuración general' },
            { route: 'admin.integrations.whatsapp.edit', match: 'admin.integrations.*', label: 'Integración de WhatsApp' },
            { route: 'admin.locations.index', match: 'admin.locations.*', label: 'Zonas y cobertura' },
            { route: 'admin.rating-reasons.index', match: 'admin.rating-reasons.*', label: 'Reglas de calificación' },
        ],
    },
    {
        key: 'monitoreo',
        label: 'Monitoreo',
        description: 'Indicadores, errores y salud operativa',
        icon: 'pulse',
        items: [
            { route: 'admin.metrics.index', match: 'admin.metrics.*', label: 'Indicadores de plataforma' },
            { route: 'admin.monitoring.index', match: 'admin.monitoring.*', label: 'Errores y monitoreo' },
        ],
    },
];
