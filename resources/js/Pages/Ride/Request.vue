<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import FleetMap from '@/Components/FleetMap.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import AddressAutocomplete from '@/Components/AddressAutocomplete.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { distanceKm } from '@/Utils/haversine';
import { fetchOsrmRoute } from '@/Utils/osrmRoute';
import { confirmDialog } from '@/Utils/confirmDialog';
import { tierColorClass, tierLabel } from '@/Utils/tierBadge';

const props = defineProps({
    fleet: { type: Object, required: true },
    // Todas las flotas del cliente (sección 7.3: multi-flota), para poder
    // cambiar de flota sin volver a la lista si tiene más de una.
    fleets: { type: Array, required: true },
    // Zonas del Ecuador (consideración agregada al alcance): ciudades con sus
    // sectores/barrios, para indicar origen/destino sin abrir el mapa.
    cities: { type: Array, required: true },
    defaultCityId: { type: Number, default: null },
    // Conductores con estado (disponible/en carrera/desconectado) y categoría
    // por reputación, ya resueltos por el backend (consideración agregada al
    // alcance) — ver RideRequestController::driverCardData().
    fleetDrivers: { type: Array, required: true },
    publicDrivers: { type: Array, required: true },
    // Pedido explícito del usuario: si viene de "elegir un conductor" (Mi
    // flota, directorio, perfil), esta pantalla arranca con ESE conductor ya
    // elegido, no "toda la flota disponible".
    preselectedDriverId: { type: Number, default: null },
    // Pedido explícito del usuario: acceso directo "Programar carrera" desde
    // el inicio del cliente, arranca esta pantalla ya en modo "programada".
    startScheduled: { type: Boolean, default: false },
    // Fix reportado por el usuario: sin esto, el estimado de acá abajo no
    // sabía que el backend (PriceCalculator) aplica una tarifa mínima —
    // mostraba "0.7 km × $0.45/km = $0.31" para una carrera que en realidad
    // iba a cobrar el mínimo configurado.
    minimumFare: { type: Number, required: true },
    // Pedido explícito del usuario ("guardá las que ya ha realizado para que
    // aparezcan como favoritas"): direcciones que este cliente ya usó antes.
    frequentPlaces: { type: Array, default: () => [] },
    // "Mis rutas" (pedido explícito del usuario): pares completos de
    // origen+destino guardados a propósito, con alias opcional — distinto de
    // frequentPlaces (direcciones sueltas, automáticas).
    savedRoutes: { type: Array, default: () => [] },
});

// De dónde elegir conductor (consideración agregada al alcance): mi flota
// (por defecto — es el círculo de confianza, sección 3.2), el directorio
// público (sección 3.4, la red de respaldo), o ambos juntos. Si el
// conductor preseleccionado es del directorio público (no de la flota), hay
// que arrancar en "Público" para que aparezca seleccionable de una.
const sourceMode = ref(
    props.preselectedDriverId && !props.fleetDrivers.some((d) => d.user_id === props.preselectedDriverId) && props.publicDrivers.some((d) => d.user_id === props.preselectedDriverId)
        ? 'public'
        : 'fleet'
);

const STATUS_PRIORITY = { available: 0, busy: 1, offline: 2 };

// Pedido explícito del usuario: la lista de conductores no tenía un orden
// claro, y con flotas grandes (~20 conductores) era muy larga para revisarla
// entera — el disponible siempre va primero (regla que ya existía), pero
// ahora el cliente elige el criterio de desempate: más cercano (el mismo
// criterio que ya usa el despacho secuencial para "toda mi flota"), más
// económico por km, o mejor calificado.
const SORT_OPTIONS = {
    distance: 'Más cercanos',
    price: 'Más económicos',
    rating: 'Mejor calificados',
};
const sortBy = ref('distance');

function sortDrivers(list) {
    return [...list].sort((a, b) => {
        const byStatus = STATUS_PRIORITY[a.status] - STATUS_PRIORITY[b.status];
        if (byStatus !== 0) return byStatus;

        if (sortBy.value === 'price') {
            return Number(a.rate_per_km ?? Infinity) - Number(b.rate_per_km ?? Infinity);
        }
        if (sortBy.value === 'distance') {
            return (a.distance ?? Infinity) - (b.distance ?? Infinity);
        }
        return (b.average_rating ?? 0) - (a.average_rating ?? 0);
    });
}

// Copia local de "Mi flota" (consideración agregada al alcance: que el punto
// de color se actualice solo cuando un conductor prende/apaga su
// disponibilidad, sin tener que recargar la pantalla). Los del directorio
// público no se pueden actualizar en vivo acá: el broadcast de ubicación solo
// llega al canal de las flotas donde el conductor es miembro (routes/channels.php),
// y uno público no tiene por qué serlo de la mía.
const fleetDriversLocal = ref([...props.fleetDrivers]);

// El orden final (sortDrivers) se aplica más abajo, en driversWithDistance —
// recién ahí se conoce la distancia de cada conductor al origen, así que
// ordenar acá todavía sería prematuro para el criterio "más cercanos".
const visibleDrivers = computed(() => {
    const fleet = fleetDriversLocal.value.map((d) => ({ ...d, source: 'fleet' }));
    const pub = props.publicDrivers.map((d) => ({ ...d, source: 'public' }));

    if (sourceMode.value === 'fleet') return fleet;
    if (sourceMode.value === 'public') return pub;
    return [...fleet, ...pub];
});

let fleetChannel = null;

onMounted(() => {
    // Mismo evento que ya escuchan Ride/Show.vue, Ride/Index.vue y el inicio
    // del cliente (Dashboard.vue) — acá faltaba, y era justo la pantalla
    // donde más se nota (elegir a quién pedirle la carrera con el estado
    // desactualizado). No pisa "en carrera": ese dato no viaja en este
    // evento, así que si ya estaba ocupado, se mantiene hasta que se
    // complete la carrera.
    fleetChannel = window.Echo.private(`fleet.${props.fleet.id}`);
    fleetChannel.listen('.driver.location.updated', (e) => {
        const driver = fleetDriversLocal.value.find((d) => d.user_id === e.driver_user_id);
        if (driver) {
            // Bug real reportado: acá solo se actualizaba el estado
            // (disponible/en carrera), nunca la posición — la "cercanía" de
            // cada conductor contra el cliente que está pidiendo la carrera
            // se quedaba pegada en la que había al cargar la pantalla, sin
            // importar cuánto se moviera de verdad.
            driver.current_lat = e.lat;
            driver.current_lng = e.lng;
            if (driver.status !== 'busy') {
                driver.status = e.is_available ? 'available' : 'offline';
            }
        }
    });

    // El conductor terminó la carrera y queda libre de nuevo (consideración
    // agregada al alcance): sin esto, alguien que ya estaba "en carrera"
    // seguía viéndose así para siempre, aunque hubiese completado el viaje
    // hace rato.
    fleetChannel.listen('.ride.completed', (e) => {
        const driver = fleetDriversLocal.value.find((d) => d.user_id === e.driver_user_id);
        if (driver) {
            driver.status = e.is_available ? 'available' : 'offline';
        }
    });
});

onBeforeUnmount(() => {
    window.Echo.leave(`fleet.${props.fleet.id}`);
});

const STATUS_STYLE = {
    available: { dot: 'bg-arka-primary', label: 'Disponible', textClass: '' },
    busy: { dot: 'bg-arka-warning', label: 'En carrera', textClass: '' },
    offline: { dot: 'bg-arka-text-muted', label: 'Desconectado', textClass: 'opacity-50 grayscale' },
};

// Arranca en la ciudad donde vive el cliente (Mi perfil); si no la eligió
// todavía, la primera del catálogo, para no dejar el selector vacío.
const selectedCityId = ref(props.defaultCityId ?? props.cities[0]?.id ?? null);
const selectedCity = computed(() => props.cities.find((c) => c.id === selectedCityId.value));

// Catálogos para el combobox con buscador (Components/SearchableSelect.vue) —
// ~30 ciudades y decenas de sectores por ciudad, mucho para un <select> nativo.
const cityOptions = computed(() => props.cities.map((city) => ({ value: city.id, label: city.name })));
// Para que el autocompletado de direcciones prefiera resultados cerca de la
// ciudad elegida (Components/AddressAutocomplete.vue), sin restringir el resto.
const cityBias = computed(() =>
    selectedCity.value?.lat ? { lat: Number(selectedCity.value.lat), lng: Number(selectedCity.value.lng) } : null
);
const sectorOptions = computed(() => (selectedCity.value?.sectors ?? []).map((sector) => ({ value: sector.id, label: sector.name })));

const originSectorId = ref(null);
const destinationSectorId = ref(null);
const originAddress = ref('');

// Simplificar el pedido de carrera (pedido explícito del usuario): ciudad y
// sector quedan como un ajuste opcional, colapsado — buscar la dirección con
// Google (arriba) ya alcanza para la gran mayoría de los casos.
const showZoneDetails = ref(false);

// "toda la flota" se representa como null en driver_user_id (sección 3.5).
const WHOLE_FLEET = null;

// Despacho secuencial estilo Uber (pedido explícito del usuario): la etiqueta
// de la opción "sin conductor puntual" cambia según la bolsa elegida, porque
// ya no es solo "la flota" — puede ser el directorio público o ambos.
const WHOLE_POOL_LABEL = {
    fleet: 'Toda mi flota disponible',
    public: 'Todo el directorio público',
    both: 'Mi flota y el directorio público',
};

const preselectableIds = new Set([...props.fleetDrivers, ...props.publicDrivers].map((d) => d.user_id));
const selectedDriverId = ref(
    props.preselectedDriverId && preselectableIds.has(props.preselectedDriverId) ? props.preselectedDriverId : WHOLE_FLEET
);

// Menos vueltas para pedir una carrera (pedido explícito del usuario, a
// partir de un mockup acordado antes de tocar esto): "¿Cuántos van?" y la
// lista de conductores puntuales arrancan cerrados, porque casi siempre
// valen lo mismo (1 pasajero, sin cajuela, efectivo, toda mi flota) — se
// abren solo si hace falta cambiar algo. Nada de lo que había se sacó.
const showRideOptions = ref(false);
// Si ya viene con un conductor puntual elegido (ej. desde "Mi flota" o el
// directorio), el picker arranca abierto para que se vea de una quién es.
const showDriverPicker = ref(selectedDriverId.value !== WHOLE_FLEET);

const originLat = ref(null);
const originLng = ref(null);
const locatingOrigin = ref(true);
const locationError = ref('');

const destinationLat = ref(null);
const destinationLng = ref(null);
const destinationAddress = ref('');

// Centro del mapa (consideración agregada al alcance): arranca en la
// ubicación real del cliente (geolocalización); si cambia de ciudad a mano,
// el mapa se recentra ahí — ver changeCity() más abajo.
const mapCenter = ref(null);

// Geocodificación inversa gratis, sin API key (OpenStreetMap Nominatim —
// mismo criterio que OSRM para el trazado del recorrido, sección 9.3): para
// que el campo Origen muestre una dirección legible en vez de dejarlo vacío
// con solo el pin puesto en el mapa.
async function reverseGeocode(lat, lng) {
    try {
        const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`);
        const data = await response.json();
        return data?.display_name ?? null;
    } catch {
        // Si el servicio gratuito no responde, no rompemos el flujo — el
        // origen queda igual marcado en el mapa, solo sin texto.
        return null;
    }
}

// Pedido explícito del usuario: "que el origen también tenga la opción de
// colocar mi ubicación actual" — reutilizable tanto para el intento
// automático al abrir la pantalla como para el botón "Usar mi ubicación
// actual" (por si el primer intento falló, o el cliente se movió).
//
// Bug real reportado ("se queda pegado y borra... si escribo, borra"): la
// geolocalización + geocodificación inversa tardan un par de segundos: si el
// cliente ya empezó a escribir su propia referencia mientras tanto, el
// intento AUTOMÁTICO (silencioso) no puede pisarle lo que ya escribió cuando
// recién ahí termina de resolver. El botón explícito, en cambio, SÍ tiene que
// pisarlo siempre — es una acción a propósito del cliente.
async function useCurrentLocationAsOrigin({ overwriteAddress = true } = {}) {
    if (!navigator.geolocation) {
        locatingOrigin.value = false;
        locationError.value = 'Su navegador no soporta geolocalización.';
        return;
    }

    locatingOrigin.value = true;

    navigator.geolocation.getCurrentPosition(
        async (position) => {
            locationError.value = '';
            locatingOrigin.value = false;

            // Bug real reportado ("el precio queda pegado en un valor
            // anterior al cambiar origen/destino"): este mismo guard ya
            // protegía el TEXTO de la dirección, pero originLat/originLng se
            // pisaban sin condición ninguna un par de líneas más abajo — si
            // el cliente eligió un origen por autocompletar o en el mapa
            // MIENTRAS la geolocalización silenciosa todavía no resolvía, la
            // respuesta tardía de esta función terminaba pisando esas
            // coordenadas con las crudas del GPS cuando por fin llegaba,
            // dejando el precio calculado sobre un punto viejo aunque el
            // texto en pantalla mostrara el correcto.
            if (!overwriteAddress && originAddress.value.trim()) return;

            originLat.value = position.coords.latitude;
            originLng.value = position.coords.longitude;
            mapCenter.value = { lat: originLat.value, lng: originLng.value };
            originAddress.value = (await reverseGeocode(originLat.value, originLng.value)) ?? 'Mi ubicación actual';
        },
        () => {
            locationError.value = 'No pudimos acceder a su ubicación. Active los permisos del navegador.';
            locatingOrigin.value = false;
            // Sin geolocalización, al menos centramos en la ciudad por defecto.
            if (selectedCity.value?.lat) {
                mapCenter.value = { lat: Number(selectedCity.value.lat), lng: Number(selectedCity.value.lng) };
            }
        }
    );
}

// Apenas se abre la pantalla, tratamos de ubicar al cliente automáticamente
// (sección 3.5: "ver flota disponible" ya arranca con la posición del
// cliente) — sin pisarle la referencia si ya empezó a escribir la suya.
useCurrentLocationAsOrigin({ overwriteAddress: false });

// Pedido explícito del usuario: cantidad de pasajeros (por defecto 1) y si
// hace falta cajuela para maletas (por defecto no) — filtran qué
// conductores pueden tomar la carrera (ver driversWithDistance más abajo).
const passengerCount = ref(1);
const needsTrunk = ref(false);

// Distancia a cada conductor visible, calculada en el navegador (Haversine)
// solo para mostrarla — el precio final lo calcula el backend.
// Pedido explícito del usuario: "solo buscar los conductores que tengan esa
// característica" — se filtra, no solo se muestra en gris, a diferencia de
// "fuera de zona" (outOfRange, más abajo), que sigue siendo informativo.
const driversWithDistance = computed(() => {
    const withDistance = visibleDrivers.value
        .filter((driver) => (driver.passenger_capacity ?? 0) >= passengerCount.value && (!needsTrunk.value || driver.has_trunk))
        .map((driver) => {
            const hasLocation = driver.current_lat != null && driver.current_lng != null;
            const distance =
                hasLocation && originLat.value != null
                    ? distanceKm(originLat.value, originLng.value, Number(driver.current_lat), Number(driver.current_lng))
                    : null;

            // Zona de cobertura (pedido explícito del usuario): el conductor
            // configuró hasta qué distancia de SU ubicación quiere recibir
            // solicitudes — si el origen elegido queda más lejos que eso, no
            // se puede pedir (se valida también en el backend, esto es solo
            // para que el cliente lo vea antes de intentarlo).
            const outOfRange = distance != null && driver.max_request_distance_km != null && distance > driver.max_request_distance_km;

            return { ...driver, distance, outOfRange };
        });

    return sortDrivers(withDistance);
});

// Pedido explícito del usuario: la lista se hacía eterna con flotas grandes —
// se pagina de a 5, y cambiar de fuente/orden/filtro vuelve siempre a la
// primera página (si no, se podía quedar "en la página 3" mirando una lista
// distinta y vacía).
const DRIVERS_PER_PAGE = 5;
const currentPage = ref(1);

watch([sourceMode, sortBy, passengerCount, needsTrunk], () => {
    currentPage.value = 1;
});

const totalDriverPages = computed(() => Math.max(1, Math.ceil(driversWithDistance.value.length / DRIVERS_PER_PAGE)));

// Si la lista se achica (ej. bajó la cantidad de pasajeros y ahora entran
// menos conductores) y la página actual quedó fuera de rango.
watch(totalDriverPages, (total) => {
    if (currentPage.value > total) currentPage.value = total;
});

const pagedDrivers = computed(() => {
    const start = (currentPage.value - 1) * DRIVERS_PER_PAGE;
    return driversWithDistance.value.slice(start, start + DRIVERS_PER_PAGE);
});

const mapMarkers = computed(() => {
    // Pedido explícito del usuario: ícono de auto (no el pin celeste
    // genérico de Leaflet) para cada conductor de la lista — ver
    // Components/FleetMap.vue (ICONS.car).
    const markers = driversWithDistance.value
        .filter((driver) => driver.current_lat != null && driver.status !== 'offline')
        .map((driver) => ({
            id: 'car',
            lat: Number(driver.current_lat),
            lng: Number(driver.current_lng),
            label: driver.name,
        }));

    // Origen en el mapa (consideración agregada al alcance: con el buscador
    // ahora como campo principal, conviene confirmar visualmente los dos
    // puntos, no solo el destino).
    if (originLat.value != null) {
        markers.push({ id: 'origin', lat: originLat.value, lng: originLng.value, label: 'Origen' });
    }

    if (destinationLat.value != null) {
        markers.push({ id: 'destination', lat: destinationLat.value, lng: destinationLng.value, label: 'Destino' });
    }

    return markers;
});

function pickDestination({ lat, lng }) {
    destinationLat.value = lat;
    destinationLng.value = lng;
}

// El cliente eligió una sugerencia de Google Places para el origen (decisión
// explícita del usuario: Google solo para autocompletar direcciones, ver
// Components/AddressAutocomplete.vue) — pisa la posición de geolocalización,
// mismo efecto que si el navegador hubiera dado esa ubicación de entrada.
function pickOriginFromAddress({ lat, lng, sectorId }) {
    originLat.value = lat;
    originLng.value = lng;
    locatingOrigin.value = false;
    locationError.value = '';
    mapCenter.value = { lat, lng };
    // Un favorito (pedido explícito del usuario) ya sabe en qué sector cae —
    // no hace falta que el cliente lo vuelva a elegir a mano.
    if (sectorId) originSectorId.value = sectorId;
}

// Mismo criterio que pickDestination() (tocar el mapa), solo que el punto
// viene resuelto por Google (o por un favorito) en vez de un clic.
function pickDestinationFromAddress({ lat, lng, sectorId }) {
    destinationLat.value = lat;
    destinationLng.value = lng;
    if (sectorId) destinationSectorId.value = sectorId;
}

// Botón "X" del campo (pedido explícito del usuario): borrar el texto no
// alcanza — si quedaban lat/lng/sector de la elección anterior, el precio
// seguía calculándose sobre ese punto viejo aunque el campo se viera vacío.
function clearOrigin() {
    originLat.value = null;
    originLng.value = null;
    originSectorId.value = null;
    locationError.value = '';
}

function clearDestination() {
    destinationLat.value = null;
    destinationLng.value = null;
    destinationSectorId.value = null;
}

// --- Trazado real del recorrido (consideración agregada al alcance: "que
// trace el recorrido"), con OSRM — gratis, sin API key (sección 9.3). Se pide
// en cuanto hay origen y destino marcados — ver Utils/osrmRoute.js, mismo
// mecanismo que usan Expresos y Rutas y Turismo. ---
const routeCoords = ref([]);

watch([originLat, originLng, destinationLat, destinationLng], async () => {
    if (originLat.value == null || destinationLat.value == null) {
        routeCoords.value = [];
        return;
    }

    routeCoords.value = await fetchOsrmRoute(originLat.value, originLng.value, destinationLat.value, destinationLng.value);
});

// --- Precio sugerido (sección 5): distancia × tarifa de referencia. Es una
// estimación para mostrar antes de mandar la solicitud — el monto que
// realmente queda registrado lo calcula el backend (puede variar un poco por
// el recargo horario). Si se manda "a toda la flota", todavía no hay un
// conductor puntual, así que se usa el promedio de tarifas de la flota.
const estimatedDistanceKm = computed(() => {
    if (originLat.value == null || destinationLat.value == null) return null;
    return distanceKm(originLat.value, originLng.value, destinationLat.value, destinationLng.value);
});

const referenceRatePerKm = computed(() => {
    if (selectedDriverId.value !== WHOLE_FLEET) {
        const chosen = driversWithDistance.value.find((driver) => driver.user_id === selectedDriverId.value);
        return chosen ? Number(chosen.rate_per_km ?? 0) : 0;
    }

    const rates = driversWithDistance.value.map((driver) => Number(driver.rate_per_km ?? 0)).filter(Boolean);
    return rates.length ? rates.reduce((a, b) => a + b, 0) / rates.length : 0;
});

// Fix reportado por el usuario: si distancia × tarifa da menos que la tarifa
// mínima configurada, el backend cobra el mínimo (PriceCalculator) — acá se
// replica el mismo max(...) para que el estimado no mienta, y se marca
// cuándo se está aplicando el mínimo para ocultar el desglose por km (ya no
// tiene sentido mostrarlo si no es lo que se termina cobrando).
const rawPriceByDistance = computed(() => {
    if (estimatedDistanceKm.value == null) return null;
    return Math.round(estimatedDistanceKm.value * referenceRatePerKm.value * 100) / 100;
});

// Pedido explícito del usuario: si el conductor elegido declaró SU PROPIA
// tarifa mínima (y no supera la de la plataforma, ya validado al guardar su
// perfil), el estimado tiene que respetarla en vez de mostrar siempre la
// general — mismo criterio que referenceRatePerKm, pero sin promediar entre
// conductores en "toda la flota" (ver RideRequestController::referenceMinimumFare()).
const referenceMinimumFare = computed(() => {
    if (selectedDriverId.value === WHOLE_FLEET) return props.minimumFare;

    const chosen = driversWithDistance.value.find((driver) => driver.user_id === selectedDriverId.value);
    const driverFloor = chosen?.minimum_fare != null ? Number(chosen.minimum_fare) : null;
    return driverFloor != null ? Math.min(driverFloor, props.minimumFare) : props.minimumFare;
});

const isMinimumFareApplied = computed(() => rawPriceByDistance.value != null && rawPriceByDistance.value < referenceMinimumFare.value);

const estimatedPrice = computed(() => {
    if (rawPriceByDistance.value == null) return null;
    return Math.max(rawPriceByDistance.value, referenceMinimumFare.value);
});

// El cliente puede aceptar el precio estimado tal cual, o proponer otro monto
// desde el arranque (sección 5: "el cliente puede aceptar ese precio o hacer
// una contraoferta con otro monto").
const useCustomPrice = ref(false);
const customPrice = ref(null);

const form = useForm({
    fleet_id: props.fleet.id,
    driver_user_id: WHOLE_FLEET,
    dispatch_pool: null,
    origin_lat: null,
    origin_lng: null,
    origin_address: '',
    origin_sector_id: null,
    destination_lat: null,
    destination_lng: null,
    destination_address: '',
    destination_sector_id: null,
    offered_price: null,
    is_scheduled: false,
    scheduled_date: '',
    scheduled_time: '',
    round_trip: false,
    passenger_count: 1,
    needs_trunk: false,
    payment_method: 'efectivo',
});

// Si "toda mi flota" no tiene a quién ofrecerle la carrera, el aviso sugiere
// ampliar a público/ambos o mirar la lista — hace falta que el picker de
// conductores esté abierto para que esa sugerencia sirva de algo.
watch(
    () => form.errors.driver_user_id,
    (error) => {
        if (error) showDriverPicker.value = true;
    }
);

// "Ahora mismo" (default) o "programada" para una fecha/hora futura, con la
// opción de ida y vuelta (consideración agregada al alcance, pedido
// explícito del usuario). Fecha mínima seleccionable: hoy.
const whenMode = ref(props.startScheduled ? 'scheduled' : 'now');
const todayDateString = new Date().toISOString().slice(0, 10);
const scheduledDate = ref('');
const scheduledTime = ref('');
const roundTrip = ref(false);
// Forma de pago (pedido explícito del usuario): "efectivo" de default,
// el cliente todavía no tenía ninguna forma de elegirla.
const paymentMethod = ref('efectivo');

// Resumen de una línea para el chip cerrado de "¿Cuántos van?" (mockup
// acordado antes de tocar esto) — se recalcula solo con lo que ya hay
// elegido, no depende de nada nuevo.
const rideOptionsSummary = computed(() => {
    const pax = `${passengerCount.value} pasajero${passengerCount.value === 1 ? '' : 's'}`;
    const pay = paymentMethod.value === 'efectivo' ? 'Efectivo' : 'Transferencia';
    const trunk = needsTrunk.value ? 'con cajuela' : 'sin cajuela';
    return `${pax} · ${pay} · ${trunk}`;
});

// Cambiar de ciudad reinicia los sectores elegidos (son de la ciudad
// anterior) y recentra el mapa ahí (consideración agregada al alcance).
function changeCity(cityId) {
    selectedCityId.value = Number(cityId);
    originSectorId.value = null;
    destinationSectorId.value = null;

    if (selectedCity.value?.lat) {
        mapCenter.value = { lat: Number(selectedCity.value.lat), lng: Number(selectedCity.value.lng) };
    }
}

// "Mis rutas" (pedido explícito del usuario): tomar una guardada llena
// origen Y destino de una, sin escribir ni marcar nada en el mapa.
function useSavedRoute(savedRoute) {
    originLat.value = Number(savedRoute.origin_lat);
    originLng.value = Number(savedRoute.origin_lng);
    originAddress.value = savedRoute.origin_address ?? '';
    originSectorId.value = savedRoute.origin_sector_id;

    destinationLat.value = Number(savedRoute.destination_lat);
    destinationLng.value = Number(savedRoute.destination_lng);
    destinationAddress.value = savedRoute.destination_address ?? '';
    destinationSectorId.value = savedRoute.destination_sector_id;

    mapCenter.value = { lat: originLat.value, lng: originLng.value };
}

async function deleteSavedRoute(savedRoute) {
    if (!(await confirmDialog(`¿Eliminar "${savedRoute.alias || 'esta ruta'}" de Mis rutas?`, { danger: true }))) return;
    router.delete(route('saved-routes.destroy', savedRoute.id), { preserveScroll: true });
}

// Guardar la ruta actual (pedido explícito del usuario: "en cuanto llene mi
// ruta debería tener un check que me diga si deseo guardar esa ruta") —
// independiente de pedir la carrera, aparece apenas hay origen Y destino.
const canSaveRoute = computed(() => originLat.value != null && destinationLat.value != null);
const wantsToSaveRoute = ref(false);
const routeAlias = ref('');
const savingRoute = ref(false);

function saveRoute() {
    savingRoute.value = true;
    router.post(
        route('saved-routes.store'),
        {
            alias: routeAlias.value.trim() || null,
            origin_lat: originLat.value,
            origin_lng: originLng.value,
            origin_address: originAddress.value,
            origin_sector_id: originSectorId.value,
            destination_lat: destinationLat.value,
            destination_lng: destinationLng.value,
            destination_address: destinationAddress.value,
            destination_sector_id: destinationSectorId.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                savingRoute.value = false;
                wantsToSaveRoute.value = false;
                routeAlias.value = '';
            },
        }
    );
}

const canSubmit = computed(() => {
    if (originLat.value == null || destinationLat.value == null) return false;
    if (whenMode.value === 'scheduled') return Boolean(scheduledDate.value && scheduledTime.value);
    // Pedido explícito del usuario: no dejar mandar una contraoferta por
    // debajo del precio estimado (se validaba en el backend, pero no tenía
    // sentido dejar tocar "Pedir carrera" para que recién ahí se entere).
    if (useCustomPrice.value && estimatedPrice.value != null) {
        const proposed = Number(customPrice.value);
        if (!proposed || proposed < estimatedPrice.value) return false;
    }
    return true;
});

// Cambiar de flota recarga la pantalla con los conductores de la otra flota
// (sección 7.3: multi-flota) — es una navegación nueva, no un simple cambio
// de campo, porque los conductores disponibles vienen del backend por flota.
function changeFleet(fleetId) {
    router.get(route('ride-requests.create', { flota: fleetId }));
}

function submit() {
    form.fleet_id = props.fleet.id;
    form.driver_user_id = selectedDriverId.value;
    // Despacho secuencial estilo Uber (pedido explícito del usuario): solo
    // aplica cuando no se dirige a un conductor puntual — el backend arma la
    // bolsa de candidatos (mi flota / público / ambos) y va ofreciéndoles la
    // carrera de a uno, empezando por el más cercano.
    form.dispatch_pool = selectedDriverId.value === WHOLE_FLEET ? sourceMode.value : null;
    form.origin_lat = originLat.value;
    form.origin_lng = originLng.value;
    form.origin_address = originAddress.value;
    form.origin_sector_id = originSectorId.value;
    form.destination_lat = destinationLat.value;
    form.destination_lng = destinationLng.value;
    form.destination_address = destinationAddress.value;
    form.destination_sector_id = destinationSectorId.value;
    form.offered_price = useCustomPrice.value ? customPrice.value : null;
    form.is_scheduled = whenMode.value === 'scheduled';
    form.scheduled_date = whenMode.value === 'scheduled' ? scheduledDate.value : '';
    form.scheduled_time = whenMode.value === 'scheduled' ? scheduledTime.value : '';
    form.round_trip = roundTrip.value;
    form.passenger_count = passengerCount.value;
    form.needs_trunk = needsTrunk.value;
    form.payment_method = paymentMethod.value;

    form.post(route('ride-requests.store'));
}
</script>

<template>
    <Head title="Solicitar carrera" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Solicitar carrera</h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Selector de flota: solo aparece si el cliente tiene más de una
                     (sección 7.3, plan Multi-flota). -->
                <div v-if="fleets.length > 1" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <InputLabel value="Pedir carrera desde la flota" />
                    <SearchableSelect
                        class="mt-1"
                        :model-value="fleet.id"
                        :options="fleets.map((f) => ({ value: f.id, label: f.name }))"
                        @update:model-value="changeFleet"
                    />
                </div>

                <div v-if="locationError" class="p-4 bg-arka-card shadow rounded-arka text-sm text-arka-warning">
                    {{ locationError }}
                </div>

                <!-- ¿Cuándo? (consideración agregada al alcance, pedido explícito del
                     usuario): "ahora mismo" por defecto, o programar fecha/hora — con
                     la opción de marcarla como ida y vuelta. -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">¿Cuándo salís?</h3>

                    <div class="flex items-center gap-1 bg-arka-base/60 rounded-full p-1 text-sm w-fit">
                        <button
                            type="button"
                            class="px-4 py-1.5 rounded-full font-medium transition"
                            :class="whenMode === 'now' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted'"
                            @click="whenMode = 'now'"
                        >
                            Ahora mismo
                        </button>
                        <button
                            type="button"
                            class="px-4 py-1.5 rounded-full font-medium transition"
                            :class="whenMode === 'scheduled' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted'"
                            @click="whenMode = 'scheduled'"
                        >
                            Programar viaje
                        </button>
                    </div>

                    <div v-if="whenMode === 'scheduled'" class="space-y-3 pt-1">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <InputLabel for="scheduled_date" value="Fecha" />
                                <TextInput
                                    id="scheduled_date"
                                    type="date"
                                    class="mt-1 block w-full"
                                    :min="todayDateString"
                                    v-model="scheduledDate"
                                />
                                <InputError class="mt-1" :message="form.errors.scheduled_date" />
                            </div>
                            <div>
                                <InputLabel for="scheduled_time" value="Hora" />
                                <TextInput
                                    id="scheduled_time"
                                    type="time"
                                    class="mt-1 block w-full"
                                    v-model="scheduledTime"
                                />
                                <InputError class="mt-1" :message="form.errors.scheduled_time" />
                            </div>
                        </div>

                        <label class="flex items-center gap-2">
                            <input type="checkbox" v-model="roundTrip" class="text-arka-primary rounded" />
                            <span class="text-sm text-arka-text">Es ida y vuelta</span>
                        </label>
                    </div>
                </div>

                <!-- Origen/Destino (pedido explícito del usuario: "simplificar la
                     búsqueda... que sea más fácil pedir una carrera") — buscador con
                     Google Places como campo principal, con los lugares ya usados
                     antes como favoritos (ver AddressAutocomplete.vue). -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">¿De dónde a dónde vas?</h3>

                    <!-- "Mis rutas" (pedido explícito del usuario): tomar una
                         ruta guardada de una, sin escribir ni marcar nada. -->
                    <div v-if="savedRoutes.length" class="flex flex-wrap gap-2">
                        <div
                            v-for="saved in savedRoutes"
                            :key="saved.id"
                            class="group flex items-center gap-1.5 pl-3 pr-1.5 py-1.5 rounded-full border border-arka-text-muted/20 hover:border-arka-primary/50 bg-arka-base/60"
                        >
                            <button type="button" class="text-sm text-arka-text" @click="useSavedRoute(saved)">
                                📍 {{ saved.alias || saved.origin_address || 'Ruta guardada' }}
                            </button>
                            <button
                                type="button"
                                class="text-arka-text-muted opacity-0 group-hover:opacity-100 hover:text-arka-danger text-xs px-1"
                                title="Eliminar"
                                @click="deleteSavedRoute(saved)"
                            >
                                ✕
                            </button>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <InputLabel for="origin_address" value="Origen" />
                            <!-- Pedido explícito del usuario: que el origen también
                                 tenga la opción de usar la ubicación actual, no solo
                                 el intento automático silencioso de al abrir la
                                 pantalla (por si falló, o si el cliente se movió). -->
                            <button
                                type="button"
                                class="text-xs text-arka-primary hover:text-arka-primary-bright shrink-0"
                                :disabled="locatingOrigin"
                                @click="() => useCurrentLocationAsOrigin()"
                            >
                                📍 {{ locatingOrigin ? 'Ubicándote…' : 'Usar mi ubicación actual' }}
                            </button>
                        </div>
                        <AddressAutocomplete
                            id="origin_address"
                            class="mt-1"
                            v-model="originAddress"
                            :city-bias="cityBias"
                            :favorites="frequentPlaces"
                            placeholder="Su ubicación actual o busque una dirección"
                            @place-selected="pickOriginFromAddress"
                            @clear="clearOrigin"
                        />
                    </div>

                    <div>
                        <InputLabel for="destination_address" value="Destino" />
                        <AddressAutocomplete
                            id="destination_address"
                            class="mt-1"
                            v-model="destinationAddress"
                            :city-bias="cityBias"
                            :favorites="frequentPlaces"
                            placeholder="¿A dónde vas?"
                            @place-selected="pickDestinationFromAddress"
                            @clear="clearDestination"
                        />
                    </div>

                    <!-- Guardar ruta (pedido explícito del usuario: "en cuanto
                         llene mi ruta debería tener un check que me diga si
                         deseo guardar esa ruta... con un alias... pero que no
                         sea obligatorio") — aparece apenas hay origen Y
                         destino, independiente de pedir la carrera. -->
                    <div v-if="canSaveRoute" class="pt-1">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" v-model="wantsToSaveRoute" class="text-arka-primary rounded" />
                            <span class="text-sm text-arka-text">Guardar esta ruta en "Mis rutas"</span>
                        </label>
                        <div v-if="wantsToSaveRoute" class="mt-2 flex gap-2">
                            <TextInput
                                type="text"
                                class="block w-full"
                                v-model="routeAlias"
                                placeholder="Alias (opcional): Casa, Trabajo, Paseo…"
                                maxlength="50"
                            />
                            <SecondaryButton :disabled="savingRoute" @click="saveRoute">
                                {{ savingRoute ? 'Guardando…' : 'Guardar' }}
                            </SecondaryButton>
                        </div>
                    </div>

                    <!-- Precisar sector (consideración agregada al alcance): queda
                         colapsado — buscar la dirección arriba ya alcanza para la
                         mayoría de los casos, esto es solo para afinar el sector si
                         hace falta (ej. el conductor lo entiende de un vistazo). -->
                    <button
                        type="button"
                        class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        @click="showZoneDetails = !showZoneDetails"
                    >
                        {{ showZoneDetails ? 'Ocultar' : 'Precisar ciudad/sector (opcional)' }}
                    </button>

                    <div v-if="showZoneDetails" class="space-y-3 pt-1">
                        <div>
                            <InputLabel value="Ciudad" />
                            <SearchableSelect
                                class="mt-1"
                                :model-value="selectedCityId"
                                :options="cityOptions"
                                @update:model-value="changeCity"
                            />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <InputLabel for="origin_sector_id" value="Sector donde está" />
                                <SearchableSelect
                                    id="origin_sector_id"
                                    class="mt-1"
                                    v-model="originSectorId"
                                    :options="sectorOptions"
                                    empty-label="Sin especificar"
                                />
                            </div>
                            <div>
                                <InputLabel for="destination_sector_id" value="Sector a donde vas" />
                                <SearchableSelect
                                    id="destination_sector_id"
                                    class="mt-1"
                                    v-model="destinationSectorId"
                                    :options="sectorOptions"
                                    empty-label="Sin especificar"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mapa: confirmación visual del recorrido, y una forma de ajustar el
                     destino a mano tocando el mapa (sección 9.3: Leaflet + OpenStreetMap). -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-1">Mapa</h3>
                    <p class="text-sm text-arka-text-muted mb-3">
                        También puede tocar el mapa para ajustar el destino a mano.
                    </p>

                    <FleetMap
                        :markers="mapMarkers"
                        :center="mapCenter ?? undefined"
                        :route="routeCoords"
                        :clickable="true"
                        :auto-fit="false"
                        @map-click="pickDestination"
                    />

                    <p v-if="!destinationLat" class="mt-2 text-sm text-arka-text-muted">
                        Todavía no marcaste el destino.
                    </p>
                </div>

                <!-- Pedido explícito del usuario: cantidad de pasajeros (por
                     defecto 1), si hace falta cajuela (por defecto no) y la
                     forma de pago (por defecto efectivo) — colapsado en un
                     resumen de una línea (mockup acordado antes de tocar
                     esto): casi siempre valen lo mismo, se abre solo si hace
                     falta cambiar algo. -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <button
                        type="button"
                        class="w-full flex items-center justify-between gap-2 text-start"
                        @click="showRideOptions = !showRideOptions"
                    >
                        <span class="text-sm text-arka-text">
                            <span class="text-arka-text-muted">Viajás</span> {{ rideOptionsSummary }} ✎
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-arka-text-muted transition-transform" :class="{ 'rotate-180': showRideOptions }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div v-if="showRideOptions" class="mt-3 space-y-3">
                        <div class="flex items-center gap-3">
                            <InputLabel for="passenger_count" value="Pasajeros" class="shrink-0" />
                            <TextInput
                                id="passenger_count"
                                type="number"
                                min="1"
                                max="8"
                                class="w-24"
                                v-model.number="passengerCount"
                            />
                        </div>

                        <label class="flex items-center gap-2">
                            <input type="checkbox" v-model="needsTrunk" class="text-arka-primary rounded" />
                            <span class="text-sm text-arka-text">Llevo maletas / necesito cajuela</span>
                        </label>

                        <!-- Forma de pago (pedido explícito del usuario): el conductor
                             la ve antes de aceptar — "Efectivo" queda elegido por
                             defecto. -->
                        <div>
                            <InputLabel value="Forma de pago" />
                            <div class="mt-1 flex items-center gap-1 bg-arka-base/60 rounded-full p-1 text-xs w-fit">
                                <button
                                    type="button"
                                    class="px-3 py-1.5 rounded-full font-medium transition"
                                    :class="paymentMethod === 'efectivo' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted'"
                                    @click="paymentMethod = 'efectivo'"
                                >
                                    Efectivo
                                </button>
                                <button
                                    type="button"
                                    class="px-3 py-1.5 rounded-full font-medium transition"
                                    :class="paymentMethod === 'transferencia' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted'"
                                    @click="paymentMethod = 'transferencia'"
                                >
                                    Transferencia
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Elegir conductor o toda la flota -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                        <h3 class="text-lg font-medium text-arka-text">¿A quién se la pedís?</h3>

                        <!-- Pedido explícito del usuario: que el botón de pedir esté
                             también acá arriba, al lado de "¿A quién se la pedís?" —
                             con listas largas se perdía de vista allá abajo. Mismo
                             botón, misma acción; el de más abajo (después del precio)
                             sigue estando para quien prefiere revisar todo antes. -->
                        <PrimaryButton :disabled="!canSubmit || form.processing" @click="submit">
                            {{ whenMode === 'scheduled' ? 'Programar' : 'Pedir' }}
                        </PrimaryButton>
                    </div>

                    <label
                        class="flex items-center gap-3 p-3 rounded-arka border cursor-pointer mb-2"
                        :class="selectedDriverId === WHOLE_FLEET ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-text-muted/20'"
                    >
                        <input type="radio" :value="WHOLE_FLEET" v-model="selectedDriverId" class="text-arka-primary" />
                        <span class="text-arka-text font-medium">{{ WHOLE_POOL_LABEL[sourceMode] }}</span>
                        <span class="text-sm text-arka-text-muted">(se le ofrece primero al más cercano; si no responde en 30 seg. pasa al siguiente · recomendado)</span>
                    </label>

                    <p v-if="!driversWithDistance.length && visibleDrivers.length" class="text-sm text-arka-text-muted py-3">
                        Ningún conductor de acá tiene lugar para {{ passengerCount }} pasajero(s){{ needsTrunk ? ' con cajuela' : '' }} ahora mismo.
                        Pruebe bajar la cantidad de pasajeros, sacar el filtro de cajuela, o mire el
                        <a :href="route('directory.index')" class="text-arka-primary hover:text-arka-primary-bright">directorio público</a>.
                    </p>
                    <p v-else-if="!driversWithDistance.length" class="text-sm text-arka-text-muted py-3">
                        Todavía no tiene conductores acá.
                        <a :href="route('fleet.index')" class="text-arka-primary hover:text-arka-primary-bright">Vaya a Mi Flota para invitar a alguno</a>
                        o mire el
                        <a :href="route('directory.index')" class="text-arka-primary hover:text-arka-primary-bright">directorio público</a>
                        (sección 3.4: la red de respaldo cuando nadie de su flota está disponible).
                    </p>

                    <!-- Bug reportado por el usuario: con "Mi Flota" elegida y sin
                         conductores que de verdad puedan tomarla (todos ocupados,
                         desconectados o fuera de rango), "Pedir Carrera" no hacía
                         nada visible — el backend YA rechazaba la solicitud
                         (RideRequestController::store()), pero el error nunca se
                         mostraba en pantalla. Ahora se ve, con la misma
                         recomendación de ampliar la búsqueda — y abre el picker de
                         abajo solo, para que la sugerencia sirva de algo. -->
                    <p v-if="form.errors.driver_user_id" class="text-sm text-arka-danger py-3">
                        {{ form.errors.driver_user_id }}
                        <span v-if="selectedDriverId === WHOLE_FLEET">
                            Pruebe con
                            <button type="button" class="underline hover:text-arka-danger/80" @click="sourceMode = sourceMode === 'public' ? 'both' : 'public'">
                                {{ sourceMode === 'fleet' ? 'el directorio público' : 'ambos' }}
                            </button>
                            en "¿A quién se la pide?", o agregue conductores a su flota.
                        </span>
                    </p>

                    <!-- Elegir un conductor puntual (mockup acordado antes de tocar
                         esto): arranca cerrado — "toda mi flota" ya alcanza para la
                         gran mayoría de los pedidos, no hace falta escrollear la
                         lista completa para descartarla cada vez. -->
                    <button
                        type="button"
                        class="w-full flex items-center justify-between gap-2 p-3 rounded-arka border border-arka-text-muted/20 text-start hover:border-arka-primary/50"
                        @click="showDriverPicker = !showDriverPicker"
                    >
                        <span class="text-sm text-arka-text">
                            Elegir un conductor puntual
                            <span class="text-arka-text-muted">({{ driversWithDistance.length }})</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-arka-text-muted transition-transform" :class="{ 'rotate-180': showDriverPicker }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div v-if="showDriverPicker" class="mt-3 space-y-3">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <!-- De mi flota / del directorio público / ambos (consideración
                                 agregada al alcance). -->
                            <div class="flex items-center gap-1 bg-arka-base/60 rounded-full p-1 text-xs">
                                <button
                                    type="button"
                                    class="px-3 py-1.5 rounded-full font-medium transition"
                                    :class="sourceMode === 'fleet' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted'"
                                    @click="sourceMode = 'fleet'"
                                >
                                    Mi flota
                                </button>
                                <button
                                    type="button"
                                    class="px-3 py-1.5 rounded-full font-medium transition"
                                    :class="sourceMode === 'public' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted'"
                                    @click="sourceMode = 'public'"
                                >
                                    Público
                                </button>
                                <button
                                    type="button"
                                    class="px-3 py-1.5 rounded-full font-medium transition"
                                    :class="sourceMode === 'both' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted'"
                                    @click="sourceMode = 'both'"
                                >
                                    Ambos
                                </button>
                            </div>

                            <!-- Pedido explícito del usuario: no había ningún orden claro
                                 con flotas grandes — el disponible siempre va primero,
                                 esto solo cambia el desempate entre ellos. -->
                            <div class="flex items-center gap-2 text-xs">
                                <label for="driver_sort" class="text-arka-text-muted">Ordenar por:</label>
                                <select
                                    id="driver_sort"
                                    v-model="sortBy"
                                    class="bg-arka-base/60 border-arka-text-muted/20 text-arka-text rounded-arka text-xs py-1.5 focus:border-arka-primary focus:ring-arka-primary"
                                >
                                    <option v-for="(label, value) in SORT_OPTIONS" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Disponible en verde, en carrera en naranja, desconectado "apagado"
                             (consideración agregada al alcance) — ya vienen ordenados así. -->
                        <label
                            v-for="driver in pagedDrivers"
                            :key="`${driver.source}-${driver.user_id}`"
                            class="flex items-center justify-between gap-3 p-3 rounded-arka border cursor-pointer"
                            :class="[
                                selectedDriverId === driver.user_id ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-text-muted/20',
                                STATUS_STYLE[driver.status].textClass,
                                driver.outOfRange ? 'opacity-50 grayscale' : '',
                            ]"
                        >
                            <span class="flex items-center gap-3 min-w-0">
                                <input
                                    type="radio"
                                    :value="driver.user_id"
                                    v-model="selectedDriverId"
                                    :disabled="driver.status === 'offline' || driver.outOfRange"
                                    class="text-arka-primary shrink-0"
                                />
                                <span class="h-2.5 w-2.5 rounded-full shrink-0" :class="STATUS_STYLE[driver.status].dot" :title="STATUS_STYLE[driver.status].label" />
                                <span class="min-w-0">
                                    <span class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-arka-text font-medium">{{ driver.name }}</span>
                                        <span
                                            v-if="driver.source === 'public'"
                                            class="px-1.5 py-0.5 rounded text-[10px] font-medium"
                                            :class="tierColorClass(driver.tier.color_key)"
                                        >
                                            {{ tierLabel(driver.tier) }}
                                        </span>
                                    </span>
                                    <span class="block text-sm text-arka-text-muted">
                                        ${{ driver.rate_per_km }}/km
                                        <span v-if="driver.review_count > 0">· ★ {{ Number(driver.average_rating).toFixed(1) }}</span>
                                        · {{ driver.outOfRange ? 'Fuera de su zona de cobertura' : STATUS_STYLE[driver.status].label }}
                                    </span>
                                    <!-- Pedido explícito del usuario: qué vehículo tiene, para
                                         saber qué esperar antes de pedirle la carrera. Placa
                                         tapada, no completa (confidencialidad, ver
                                         DriverProfile::maskedPlate()). -->
                                    <span v-if="driver.vehicle_make" class="block text-xs text-arka-text-muted">
                                        {{ driver.vehicle_make }} {{ driver.vehicle_model }} {{ driver.vehicle_color }}
                                        <span v-if="driver.vehicle_type"> · {{ driver.vehicle_type }}</span>
                                        · {{ driver.vehicle_plate }}
                                        · {{ driver.passenger_capacity }} pasajero(s)
                                        <span v-if="driver.has_trunk"> · con cajuela</span>
                                    </span>
                                </span>
                            </span>
                            <span v-if="driver.distance != null" class="text-sm text-arka-text-muted shrink-0">
                                {{ driver.distance.toFixed(1) }} km
                            </span>
                        </label>

                        <!-- Paginado (pedido explícito del usuario): de a 5, para no tener
                             que scrollear una lista de 20+ conductores. -->
                        <div v-if="totalDriverPages > 1" class="flex items-center justify-between gap-2 text-sm">
                            <SecondaryButton :disabled="currentPage === 1" @click="currentPage--">Anterior</SecondaryButton>
                            <span class="text-arka-text-muted">Página {{ currentPage }} de {{ totalDriverPages }}</span>
                            <SecondaryButton :disabled="currentPage === totalDriverPages" @click="currentPage++">Siguiente</SecondaryButton>
                        </div>
                    </div>
                </div>

                <!-- Precio (sección 5): estimado a partir de la distancia y la tarifa,
                     desglosado y editable — el cliente puede aceptarlo o contraofertar. -->
                <div v-if="estimatedPrice != null" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Precio</h3>

                    <div class="flex items-center justify-between text-sm text-arka-text-muted">
                        <!-- Si el mínimo configurado ya supera lo que daría distancia ×
                             tarifa, mostrar ese cálculo sería engañoso — no es lo que se
                             termina cobrando (fix reportado por el usuario). -->
                        <span v-if="isMinimumFareApplied">Tarifa mínima de la plataforma</span>
                        <span v-else>{{ estimatedDistanceKm.toFixed(1) }} km × ${{ referenceRatePerKm.toFixed(2) }}/km</span>
                        <span class="text-arka-text font-medium">${{ estimatedPrice.toFixed(2) }} (estimado)</span>
                    </div>

                    <label class="flex items-center gap-2">
                        <input type="checkbox" v-model="useCustomPrice" class="text-arka-primary rounded" />
                        <span class="text-sm text-arka-text">Proponer otro monto en vez del estimado</span>
                    </label>

                    <TextInput
                        v-if="useCustomPrice"
                        type="number"
                        step="0.01"
                        :min="estimatedPrice ?? 0.01"
                        class="block w-full"
                        v-model="customPrice"
                        placeholder="Su propuesta en USD"
                    />
                    <!-- Pedido explícito del usuario (caso real: se propuso $2 contra
                         un estimado de $3.85) — aviso inmediato en vez de esperar a que
                         el servidor lo rechace al mandar el formulario. -->
                    <p
                        v-if="useCustomPrice && customPrice && estimatedPrice != null && Number(customPrice) < estimatedPrice"
                        class="text-xs text-arka-danger"
                    >
                        No puede ser menor al precio estimado (${{ estimatedPrice.toFixed(2) }}).
                    </p>
                    <InputError :message="form.errors.offered_price" />
                </div>

                <PrimaryButton :disabled="!canSubmit || form.processing" @click="submit">
                    {{ whenMode === 'scheduled' ? 'Programar carrera' : 'Pedir carrera' }}
                </PrimaryButton>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
