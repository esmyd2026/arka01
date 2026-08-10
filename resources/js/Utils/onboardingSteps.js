// Contenido del recorrido guiado por rol (pedido explícito del usuario:
// "explicando cada uno de los módulos importantes a cada usuario nuevo...
// no muy largo pero sí lo necesario"). Separado de OnboardingTour.vue para
// poder ajustar el texto sin tocar la lógica del componente.
//
// Basado en los módulos reales de cada rol (AuthenticatedLayout.vue), no
// inventados — a propósito NO señala un botón puntual en pantalla: hoy
// existen tres listas de accesos parcialmente distintas entre sí (la grilla
// de escritorio, el bottom sheet de móvil y las tarjetas del Dashboard), así
// que apuntar a un elemento exacto del DOM habría quedado frágil entre
// dispositivos y desalineado en cuanto cualquiera de esas listas cambie.

export const clientOnboardingSteps = [
    {
        title: '¡Bienvenido/a a Arka01!',
        description: 'Le mostramos rápido dónde está cada cosa antes de arrancar. Son solo unos pasos.',
    },
    {
        title: 'Mi flota',
        description: 'Arme su lista de conductores de confianza invitándolos por teléfono — es lo primero que conviene hacer.',
    },
    {
        title: 'Directorio de conductores',
        description: 'Si nadie de su flota está disponible, acá encuentra conductores públicos verificados.',
    },
    {
        title: 'Pedir una carrera',
        description: 'Pida un viaje ahora o programado — a toda su flota disponible o a un conductor puntual.',
    },
    {
        title: 'Mis Expresos',
        description: 'Si hace siempre el mismo recorrido (ej. al trabajo), arme una ruta fija y recurrente en vez de pedirla cada vez.',
    },
];

export const driverOnboardingSteps = [
    {
        title: '¡Bienvenido/a a Arka01!',
        description: 'Le mostramos rápido dónde está cada cosa antes de arrancar. Son solo unos pasos.',
    },
    {
        title: 'Mi perfil de conductor',
        description: 'Complete los datos de su vehículo para activar el perfil y empezar a recibir carreras.',
    },
    {
        title: 'Mis clientes',
        description: 'Acepte las invitaciones de flota que le lleguen — son quienes le van a pedir carreras primero.',
    },
    {
        title: 'Carreras',
        description: 'Ahí ve y responde las solicitudes que le llegan, y el historial de las que ya hizo.',
    },
    {
        title: 'Expresos disponibles',
        description: 'Postúlese a rutas fijas y recurrentes que publican sus clientes.',
    },
];
