<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import FleetMap from '@/Components/FleetMap.vue';
import BottomSheet from '@/Components/BottomSheet.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import AddressAutocomplete from '@/Components/AddressAutocomplete.vue';
import ArkaRouteLoader from '@/Components/ArkaRouteLoader.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import DriverCategoryBadge from '@/Components/DriverCategoryBadge.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { distanceKm } from '@/Utils/haversine';
import { fetchOsrmRoute, fetchOsrmMultiRoute } from '@/Utils/osrmRoute';
import { confirmDialog } from '@/Utils/confirmDialog';
import { tierColorClass, tierLabel } from '@/Utils/tierBadge';
import { etaMinutes } from '@/Utils/eta';
import { roundUpToDime } from '@/Utils/currency';

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
    initialCategory: { type: String, default: null },
    // Pedido explícito del usuario: acceso directo "Programar carrera" desde
    // el inicio del cliente, arranca esta pantalla ya en modo "programada".
    startScheduled: { type: Boolean, default: false },
    // Fix reportado por el usuario: sin esto, el estimado de acá abajo no
    // sabía que el backend (PriceCalculator) aplica una tarifa mínima —
    // mostraba "0.7 km × $0.45/km = $0.31" para una carrera que en realidad
    // iba a cobrar el mínimo configurado.
    minimumFare: { type: Number, required: true },
    // Cargo por trayecto de recogida (pedido explícito del usuario: "debe
    // bajar un costo porque la recogida es más cerca") — el precio mostrado
    // por conductor en la lista de "Elige tu conductor" incluye este
    // estimado, replicando App\Services\PriceCalculator::pickupSurcharge().
    pickupSurchargeThresholdKm: { type: Number, required: true },
    pickupSurchargePercent: { type: Number, required: true },
    // Bug real reportado por el usuario ("sale que un conductor cobra 2.00
    // y cuando pide la carrera sale que es 2.30"): faltaba replicar acá el
    // recargo nocturno/pico que App\Services\PriceCalculator::
    // suggestedPrice() sí aplica al crear la solicitud — ver
    // isTimeSurchargeApplicable()/timeSurchargePercent() más abajo.
    timeSurcharge: { type: Object, required: true },
    // Pedido explícito del usuario ("guardá las que ya ha realizado para que
    // aparezcan como favoritas"): direcciones que este cliente ya usó antes.
    frequentPlaces: { type: Array, default: () => [] },
    // "Mis rutas" (pedido explícito del usuario): pares completos de
    // origen+destino guardados a propósito, con alias opcional — distinto de
    // frequentPlaces (direcciones sueltas, automáticas).
    savedRoutes: { type: Array, default: () => [] },
    // Cooperativas verificadas que el cliente agregó previamente a su red.
    cooperatives: { type: Array, default: () => [] },
    preselectedCooperativeId: { type: Number, default: null },
    // Rediseño UX (pedido explícito del usuario): si se llega desde el
    // buscador "¿A dónde vas?" de Inicio, el destino ya viene elegido — ver
    // RideRequestController::create().
    initialDestination: { type: Object, default: null },
    // Documento formal de ajuste UX, sección 13: si Inicio ya sabía la
    // ubicación en vivo del cliente, el origen viene resuelto de una vez —
    // evita pedir geolocalización por segunda vez acá (ver onMounted).
    initialOrigin: { type: Object, default: null },
    initialOptions: { type: Object, default: () => ({}) },
});

// El mismo fondo se aplica tanto al layout como al contenido del primer
// paso. Así, los espacios estructurales que existen entre la cabecera y la
// página (por ejemplo, el área del aviso de permisos) no dejan ver una
// franja negra distinta al degradado.
const destinationBackground = 'radial-gradient(circle at 18% 0%, rgba(52, 211, 153, 0.18) 0%, transparent 32%), radial-gradient(circle at 90% 28%, rgba(110, 231, 183, 0.07) 0%, transparent 26%), linear-gradient(180deg, #10271d 0%, #0b1b14 52%, #07110d 100%)';

const cooperativeSearch = ref('');
const filteredCooperatives = computed(() => {
    const term = cooperativeSearch.value.trim().toLocaleLowerCase('es');
    return props.cooperatives.filter((cooperative) => !term || cooperative.name.toLocaleLowerCase('es').includes(term));
});

// De dónde elegir conductor (consideración agregada al alcance): mi flota
// (por defecto — es el círculo de confianza, sección 3.2), el directorio
// público (sección 3.4, la red de respaldo), o ambos juntos. Si el
// conductor preseleccionado es del directorio público (no de la flota), hay
// que arrancar en "Público" para que aparezca seleccionable de una.
const sourceMode = ref(
    props.initialCategory === 'all'
        ? 'both'
        : props.initialCategory === 'public'
          ? 'public'
          : props.preselectedDriverId && !props.fleetDrivers.some((d) => d.user_id === props.preselectedDriverId) && props.publicDrivers.some((d) => d.user_id === props.preselectedDriverId)
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
const SORT_OPTION_LIST = Object.entries(SORT_OPTIONS).map(([value, label]) => ({ value, label }));
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

const originSectorId = ref(props.initialOrigin?.sector_id ?? null);
const destinationSectorId = ref(props.initialDestination?.sector_id ?? null);
const originAddress = ref(props.initialOrigin?.address ?? '');

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
const selectedCooperativeId = ref(
    props.cooperatives.some((cooperative) => cooperative.id === props.preselectedCooperativeId)
        ? props.preselectedCooperativeId
        : null
);

watch(selectedCooperativeId, (value) => {
    if (value) selectedDriverId.value = WHOLE_FLEET;
});

// Menos vueltas para pedir una carrera (pedido explícito del usuario, a
// partir de un mockup acordado antes de tocar esto): "¿Cuántos van?" y la
// lista de conductores puntuales arrancan cerrados, porque casi siempre
// valen lo mismo (1 pasajero, sin cajuela, efectivo, toda mi flota) — se
// abren solo si hace falta cambiar algo. Nada de lo que había se sacó.
const showRideOptions = ref(false);

// Rediseño UX (pedido explícito del usuario, guiado por
// ARKA01_Rediseno_UX_Flujo_Carreras.md): "máximo 3 acciones" — destino,
// elegir conductor, pedir ahora. Esta pantalla pasa a tener pasos internos
// en vez de un solo scroll largo; ver el paso 'driver' más abajo, que
// reemplaza al viejo showDriverPicker por las 4 categorías del documento
// (Flota → Cooperativas → Públicos → Todos).
// Si el destino ya viene elegido desde el buscador de Inicio
// (initialDestination), esta pantalla arranca directo en "Elige tu
// conductor" — el paso 'destination' no tiene nada que agregar en ese caso.
const step = ref(props.initialDestination ? 'driver' : 'destination');
// Si ya viene con un conductor puntual elegido (ej. desde "Conductores que
// quizás conozcas"), la categoría correspondiente arranca ya elegida —
// mismo criterio que ya resolvía `sourceMode` más arriba para ese mismo
// caso (sus valores iniciales son siempre 'fleet' o 'public', nunca 'both').
const activeCategory = ref(
    selectedCooperativeId.value
        ? 'cooperative'
        : props.initialCategory
          ? props.initialCategory
          : (selectedDriverId.value !== WHOLE_FLEET ? sourceMode.value : null)
);

// Documento formal de ajuste UX, sección 13: si Inicio ya mandó la ubicación
// en vivo del cliente como origen, arranca resuelto de una vez — el guard de
// `useCurrentLocationAsOrigin()` de más abajo (`!overwriteAddress &&
// originAddress.value.trim()`) ya evita que la geolocalización automática
// silenciosa lo pise después, sin tocar esa lógica.
const originLat = ref(props.initialOrigin?.lat ?? null);
const originLng = ref(props.initialOrigin?.lng ?? null);
const locatingOrigin = ref(!props.initialOrigin);
const locationError = ref('');

const destinationLat = ref(props.initialDestination?.lat ?? null);
const destinationLng = ref(props.initialDestination?.lng ?? null);
const destinationAddress = ref(props.initialDestination?.address ?? '');

// Paradas adicionales (pedido explícito del usuario: "agregar una parada
// adicional... solo permitir 4 paradas", cada una cobrada por separado —
// ver el `watch` de ruta/precio más abajo y MAX_STOPS). Cada parada es
// {lat, lng, address, sectorId}, mismo shape que origen/destino por
// separado en vez de un objeto anidado, para reusar AddressAutocomplete tal
// cual (v-model plano).
const MAX_STOPS = 4;
const stops = ref([]);

function addStop() {
    if (stops.value.length >= MAX_STOPS) return;
    stops.value.push({ lat: null, lng: null, address: '', sectorId: null });
}

function removeStop(index) {
    stops.value.splice(index, 1);
}

// Centro del mapa (consideración agregada al alcance): arranca en la
// ubicación real del cliente (geolocalización); si cambia de ciudad a mano,
// el mapa se recentra ahí — ver changeCity() más abajo. Si ya llega un
// `initialOrigin` (documento formal de ajuste UX, sección 13), arranca
// centrado ahí directo — la geolocalización automática silenciosa no lo va
// a resolver esta vez (ver el guard de useCurrentLocationAsOrigin()).
const mapCenter = ref(props.initialOrigin ? { lat: props.initialOrigin.lat, lng: props.initialOrigin.lng } : null);

// El cliente elige qué punto desea ajustar antes de tocar el mapa.
const mapEditingPoint = ref('destination');
const resolvingDestinationSelection = ref(false);
const resolvingMapPoint = ref(false);

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
if (props.initialOrigin) {
    const genericOrigin = /^mi ubicaci[oó]n/i.test(originAddress.value.trim());
    if (genericOrigin || !originAddress.value.trim()) {
        reverseGeocode(originLat.value, originLng.value).then((address) => {
            originAddress.value = address ?? `Punto GPS ${Number(originLat.value).toFixed(5)}, ${Number(originLng.value).toFixed(5)}`;
        });
    }
} else {
    useCurrentLocationAsOrigin({ overwriteAddress: false });
}

// Pedido explícito del usuario: cantidad de pasajeros (por defecto 1) y si
// hace falta cajuela para maletas (por defecto no) — filtran qué
// conductores pueden tomar la carrera (ver driversWithDistance más abajo).
const passengerCount = ref(props.initialOptions.passenger_count ?? 1);
const needsTrunk = ref(props.initialOptions.needs_trunk ?? false);

// Distancia a cada conductor, calculada en el navegador (Haversine) solo
// para mostrarla — el precio final lo calcula el backend.
// Pedido explícito del usuario: "solo buscar los conductores que tengan esa
// característica" — se filtra, no solo se muestra en gris, a diferencia de
// "fuera de zona" (outOfRange, más abajo), que sigue siendo informativo.
//
// Rediseño UX: antes esto dependía de `visibleDrivers` (a su vez atado a
// `sourceMode`), así que solo se podía conocer la lista de UNA fuente a la
// vez. Las 4 tarjetas de categoría (Flota/Cooperativas/Públicos/Todos)
// necesitan sus contadores TODOS a la vez, antes de que el cliente elija
// ninguna — se extrae el cálculo a una función reusable en vez de
// duplicarlo por categoría.
function withDistanceAndFilters(list, sourceTag) {
    const withDistance = list
        .map((driver) => ({ ...driver, source: sourceTag }))
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

            return { ...driver, distance, outOfRange, etaMinutes: etaMinutes(distance) };
        });

    return sortDrivers(withDistance);
}

const fleetCandidates = computed(() => withDistanceAndFilters(fleetDriversLocal.value, 'fleet'));
const publicCandidates = computed(() => withDistanceAndFilters(props.publicDrivers, 'public'));
const allCandidates = computed(() => sortDrivers([...fleetCandidates.value, ...publicCandidates.value]));

// Busca un conductor por id en las listas CRUDAS (sin el filtro de
// pasajeros/cajuela que ya aplica withDistanceAndFilters) — para mostrar la
// info del conductor ya elegido en el paso "Confirma y pide" aunque
// después, en el cajón de opciones, se suba la cantidad de pasajeros por
// encima de lo que ese conductor admite (si no, el conductor "desaparecía"
// de la pantalla de confirmación apenas dejaba de calzar el filtro).
function findDriverById(id) {
    return fleetDriversLocal.value.find((d) => d.user_id === id) ?? props.publicDrivers.find((d) => d.user_id === id) ?? null;
}

const selectedDriverInfo = computed(() => {
    if (selectedDriverId.value === WHOLE_FLEET) return null;
    const raw = findDriverById(selectedDriverId.value);
    if (!raw) return null;

    const distance =
        raw.current_lat != null && originLat.value != null
            ? distanceKm(originLat.value, originLng.value, Number(raw.current_lat), Number(raw.current_lng))
            : null;

    return { ...raw, distance, etaMinutes: etaMinutes(distance) };
});

// Aviso en el paso "Confirma y pide" (no bloquea: el backend valida esto de
// verdad al mandar la solicitud) — si después de elegir un conductor
// puntual se suben los pasajeros o se pide cajuela desde el cajón de
// opciones y ya no calza, mejor decirlo ahí mismo que dejar que rebote sola
// la solicitud.
const selectedDriverStillFits = computed(() => {
    if (!selectedDriverInfo.value) return true;
    return (
        (selectedDriverInfo.value.passenger_capacity ?? 0) >= passengerCount.value &&
        (!needsTrunk.value || selectedDriverInfo.value.has_trunk)
    );
});

const selectedCooperativeInfo = computed(() => props.cooperatives.find((c) => c.id === selectedCooperativeId.value) ?? null);

// Jerarquía del documento de rediseño: Flota → Cooperativas → Públicos →
// Todos — NUNCA como 4 opciones equivalentes (sección 29), Flota siempre
// primero y más grande visualmente (ver template). "Cooperativas" no tiene
// lista de conductores propia (el backend no expone eso todavía, solo
// cuántas unidades activas tiene cada cooperativa — RideDispatchCandidates::forCooperative()
// recién arma candidatos al despachar de verdad) — se muestran las
// cooperativas de la red del cliente, elegir una es la selección en sí,
// igual que ya hace el `<select>` de hoy.
// `badgeClass` (pedido explícito del usuario, con el bosquejo como
// referencia): un ícono por categoría, en una insignia circular de color
// propio — Flota en verde (la principal), Cooperativas en ámbar (como el
// escudo del bosquejo), Públicos/Todos neutros.
const CATEGORY_META = {
    fleet: { label: 'Conductores de tu flota', hint: 'Siempre primero', badgeClass: 'bg-arka-primary/15 text-arka-primary' },
    cooperative: { label: 'Cooperativas', hint: 'Unidades verificadas', badgeClass: 'bg-arka-warning/15 text-arka-warning' },
    public: { label: 'Conductores públicos', hint: 'Verificados', badgeClass: 'bg-arka-text-muted/15 text-arka-text-muted' },
    all: { label: 'Todos', hint: 'Mi flota y el directorio público', badgeClass: 'bg-arka-text-muted/15 text-arka-text-muted' },
};
const CATEGORY_ORDER = ['fleet', 'cooperative', 'public', 'all'];

const categoryCounts = computed(() => ({
    fleet: fleetCandidates.value.length,
    cooperative: props.cooperatives.length,
    public: publicCandidates.value.length,
    all: allCandidates.value.length,
}));

// La lista que se ve expandida es la de la categoría activa nada más — la
// paginación/orden de acá abajo (pagedDrivers) sigue funcionando igual que
// antes, solo que ahora filtra por categoría en vez de por `sourceMode` a
// secas.
const driversWithDistance = computed(() => {
    if (activeCategory.value === 'fleet') return fleetCandidates.value;
    if (activeCategory.value === 'public') return publicCandidates.value;
    if (activeCategory.value === 'all') return allCandidates.value;
    return [];
});

// Mantiene sourceMode al día con la categoría elegida — sigue siendo lo que
// submit() manda como dispatch_pool cuando se pide "toda la categoría
// disponible" (WHOLE_FLEET), sin tocar esa lógica.
watch(activeCategory, (category) => {
    if (category === 'fleet') sourceMode.value = 'fleet';
    else if (category === 'public') sourceMode.value = 'public';
    else if (category === 'all') sourceMode.value = 'both';

    if (category === 'cooperative') {
        selectedDriverId.value = WHOLE_FLEET;
        selectedCooperativeId.value ??= recommendedCooperative.value?.id ?? null;
    } else {
        // Una cooperativa elegida previamente no debe seguir dominando un
        // pedido cuando el cliente cambia de vuelta a Flota/Públicos/Todos.
        selectedCooperativeId.value = null;
    }
});

function nextNonEmptyCategory(from) {
    const startIndex = CATEGORY_ORDER.indexOf(from);
    for (let i = 1; i <= CATEGORY_ORDER.length; i++) {
        const candidate = CATEGORY_ORDER[(startIndex + i) % CATEGORY_ORDER.length];
        if (categoryCounts.value[candidate] > 0) return candidate;
    }
    return null;
}

// Recomendación en cascada (sección 30 del documento: "no dejar al usuario
// bloqueado") — ya NO es la categoría por defecto (pedido explícito del
// usuario: "deja por defecto todos los conductores", con el bosquejo
// mostrando "Todos los disponibles" como punto de partida). Se usa solo como
// respaldo cuando "Todos" queda vacío, y para el botón "Pruebe con..." que
// aparece si el servidor rechaza al conductor elegido.
function recommendCategory() {
    if (fleetCandidates.value.some((driver) => driver.status === 'available')) return 'fleet';
    if (props.cooperatives.length === 1) return 'cooperative';
    if (publicCandidates.value.some((driver) => driver.status === 'available')) return 'public';
    if (allCandidates.value.length) return 'all';
    if (props.cooperatives.length) return 'cooperative';
    return categoryCounts.value.fleet ? 'fleet' : null;
}

// Selección por defecto al entrar a "Elige tu conductor" (pedido explícito
// del usuario): "Todos" salvo que esté vacía, ahí sí cae en cascada. Se
// preselecciona el primero de la lista (el más cercano, sortBy='distance')
// para que "Ver disponibles"/"Pedir ahora" ya tengan a quién apuntar sin
// perder la posibilidad de elegir otro con un toque.
watch(step, (value) => {
    if (value !== 'driver' || activeCategory.value) return;

    activeCategory.value = categoryCounts.value.all > 0 ? 'all' : recommendCategory();

    if (activeCategory.value === 'fleet' && fleetCandidates.value.length) {
        selectedDriverId.value = fleetCandidates.value[0].user_id;
    } else if (activeCategory.value === 'public' && publicCandidates.value.length) {
        selectedDriverId.value = publicCandidates.value[0].user_id;
    } else if (activeCategory.value === 'all' && allCandidates.value.length) {
        selectedDriverId.value = allCandidates.value[0].user_id;
    } else if (activeCategory.value === 'cooperative') {
        selectedCooperativeId.value = recommendedCooperative.value?.id ?? null;
    }
});

// Pedido explícito del usuario: la lista se hacía eterna con flotas grandes —
// se pagina de a 5, y cambiar de fuente/orden/filtro vuelve siempre a la
// primera página (si no, se podía quedar "en la página 3" mirando una lista
// distinta y vacía).
const DRIVERS_PER_PAGE = 5;
const currentPage = ref(1);

watch([activeCategory, sourceMode, sortBy, passengerCount, needsTrunk], () => {
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

// Color del auto por categoría (pedido explícito del usuario: "unos de mi
// flota, otros de cooperativa... colocar que sea amarillo, los públicos") —
// verde de flota (mismo tono que la insignia "Conductores de tu flota"),
// azul para públicos. Las cooperativas no tienen unidades individuales que
// mostrar acá: el backend nunca expone la posición en vivo de cada unidad de
// una cooperativa al cliente (solo cuántas hay disponibles en total, ver
// RideDispatchCandidates::forCooperative()) — si se quiere ese mismo
// tratamiento (marcador amarillo por unidad), hace falta un endpoint nuevo,
// no es solo un cambio de color acá.
const DRIVER_MARKER_COLOR = { fleet: '#34d399', public: '#60a5fa' };

const mapMarkers = computed(() => {
    // Pedido explícito del usuario: ícono de auto (no el pin celeste
    // genérico de Leaflet) para cada conductor de la lista — ver
    // Components/FleetMap.vue (ICONS.car). Usa `allCandidates` (no
    // `driversWithDistance`, que ahora depende de qué categoría está
    // desplegada) para que el mapa siga mostrando a todos los candidatos
    // conocidos sin importar cuál tarjeta esté abierta. Pedido explícito del
    // usuario ("que no me aparezca el nombre si no a que categoría
    // pertenece"): el globo al tocar el auto ya no muestra el nombre del
    // conductor — privacidad de paso, y de una resuelve el pedido de
    // colorear por categoría.
    const markers = allCandidates.value
        .filter((driver) => driver.current_lat != null && driver.status !== 'offline')
        .map((driver) => ({
            // Antes compartían el mismo id 'car' — no importaba porque el
            // mapa borraba y recreaba todos los marcadores en cada
            // actualización. El movimiento suave (rediseño puramente
            // visual, GoogleFleetMap.vue) necesita distinguir cada
            // conductor entre una actualización y la siguiente.
            id: `car-${driver.user_id}`,
            type: 'car',
            lat: Number(driver.current_lat),
            lng: Number(driver.current_lng),
            label: driver.source === 'fleet' ? 'Conductor de tu flota' : 'Conductor público',
            color: DRIVER_MARKER_COLOR[driver.source] ?? DRIVER_MARKER_COLOR.public,
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

    // Cada parada usa una identidad propia y estable. Además de evitar el pin
    // genérico de ubicación, esto permite numerarlas y encuadrarlas junto con
    // el origen y el destino sin confundirlas con ninguno de los dos.
    stops.value.forEach((stop, index) => {
        if (stop.lat == null) return;
        markers.push({
            id: `stop-${index + 1}`,
            type: 'stop',
            order: index + 1,
            lat: stop.lat,
            lng: stop.lng,
            label: `Parada ${index + 1}`,
        });
    });

    return markers;
});

// El mapa debe mostrar el recorrido completo. Antes solo encuadraba origen y
// destino, por lo que una parada alejada podía quedar cortada o pegada al
// borde aun cuando la línea sí pasaba por ella.
const routeFitMarkerIds = computed(() => [
    'origin',
    ...stops.value.map((_, index) => `stop-${index + 1}`),
    'destination',
]);

async function pickRoutePoint({ lat, lng }) {
    resolvingMapPoint.value = true;

    try {
        const address = await reverseGeocode(lat, lng);

        if (mapEditingPoint.value === 'origin') {
            originLat.value = lat;
            originLng.value = lng;
            originSectorId.value = null;
            mapCenter.value = { lat, lng };
            if (address) originAddress.value = address;
            return;
        }

        if (mapEditingPoint.value.startsWith('stop-')) {
            const index = Number(mapEditingPoint.value.slice('stop-'.length));
            const stop = stops.value[index];
            if (!stop) return;
            stop.lat = lat;
            stop.lng = lng;
            stop.sectorId = null;
            if (address) stop.address = address;
            return;
        }

        destinationLat.value = lat;
        destinationLng.value = lng;
        destinationSectorId.value = null;
        if (address) destinationAddress.value = address;
    } finally {
        resolvingMapPoint.value = false;
    }
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
    // Mantiene el loader continuo entre la resolución de Google Places y el
    // inicio del cálculo de ruta que dispara el watch de coordenadas.
    if (destinationLat.value !== lat || destinationLng.value !== lng) routeLoading.value = true;
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

// Mismo criterio que pickOriginFromAddress()/pickDestinationFromAddress()
// para una parada puntual (pedido explícito del usuario).
function pickStopFromAddress(index, { lat, lng, sectorId }) {
    const stop = stops.value[index];
    if (!stop) return;
    stop.lat = lat;
    stop.lng = lng;
    if (sectorId) stop.sectorId = sectorId;
}

function clearStop(index) {
    const stop = stops.value[index];
    if (!stop) return;
    stop.lat = null;
    stop.lng = null;
    stop.sectorId = null;
}

// --- Trazado real del recorrido (consideración agregada al alcance: "que
// trace el recorrido"), con OSRM — gratis, sin API key (sección 9.3). Se pide
// en cuanto hay origen y destino marcados — ver Utils/osrmRoute.js, mismo
// mecanismo que usan Expresos y Rutas y Turismo. ---
const routeCoords = ref([]);
// Bug real confirmado (pedido explícito del usuario: "probá el mapa... en
// temas de km"): probando dos rutas reales de Guayaquil contra este mismo
// servidor OSRM, la línea recta (Haversine) dio hasta un tercio de la
// distancia real de manejo en calles con curvas o sin conexión directa. Acá
// se guarda la distancia REAL de la ruta que ya se dibuja en el mapa, para
// usarla en el estimado y mandarla al backend — ver estimatedDistanceKm más
// abajo y submit().
const routeDistanceKm = ref(null);
// Duración real de manejo de la misma respuesta de OSRM (pedido explícito
// del usuario: "indicar los km y minutos de ese recorrido" en la tarjeta
// fija de origen/destino).
const routeDurationMin = ref(null);
const routeLoading = ref(false);
let routeRequestId = 0;

const destinationLoading = computed(() =>
    step.value === 'destination'
    && (resolvingDestinationSelection.value || resolvingMapPoint.value || routeLoading.value)
);
const destinationLoadingTitle = computed(() => {
    if (resolvingDestinationSelection.value) return 'Ubicando tu destino';
    if (resolvingMapPoint.value) return 'Confirmando el punto';
    return 'Preparando tu recorrido';
});

// Bug real reportado por el usuario ("no traza el recorrido"): al llegar
// directo al paso 'driver' desde el buscador de Inicio, origen Y destino ya
// vienen resueltos en las props (initialOrigin/initialDestination) DESDE
// que se crean estos refs — un `watch` sin `immediate` solo dispara ante un
// CAMBIO posterior, así que nunca llegaba a pedir la ruta en ese caso (sí
// funcionaba viniendo del paso 'destination' de esta misma pantalla, donde
// los valores sí cambian recién al elegir origen/destino a mano).
// Tramos de las paradas (pedido explícito del usuario: "esto recalcule las
// rutas y los costos siempre que existan paradas") — cada elemento es el
// tramo que TERMINA en esa parada (origen→parada1, parada1→parada2, etc.),
// espejo de lo que calcula RideRequestController::store() por tramo.
// routeCoords/routeDistanceKm/routeDurationMin (de arriba) siguen
// representando SOLO el tramo final (últimaParada→destino, u
// origen→destino sin paradas) — mismo significado exacto que hoy, sin
// paradas esto no cambia en nada.
const stopLegs = ref([]);

// Bug real reportado por el usuario ("parece que calcula el km de la
// primera parada al destino y no el origen-parada-...-destino"): el "X km ·
// Y min" de la tarjeta de resumen mostraba SOLO routeDistanceKm/
// routeDurationMin (el tramo final, ver el comentario de arriba) — correcto
// para el precio, pero engañoso como "distancia total del viaje" cuando hay
// paradas. Puramente para mostrar: suma el tramo final + todos los tramos
// de las paradas. NO se usa para precio ni se manda al backend — eso sigue
// siendo routeDistanceKm tal cual, sin tocar el contrato con el servidor.
const totalTripDistanceKm = computed(() => {
    if (routeDistanceKm.value == null) return null;
    return stopLegs.value.reduce((sum, leg) => sum + (leg.distanceKm ?? 0), routeDistanceKm.value);
});
const totalTripDurationMin = computed(() => {
    if (routeDurationMin.value == null) return null;
    return stopLegs.value.reduce((sum, leg) => sum + (leg.durationMin ?? 0), routeDurationMin.value);
});

// El texto del buscador NO forma parte del trazado. Antes el `watch` profundo
// observaba el objeto completo de `stops`, incluido `address`, y cada tecla
// volvía a consultar OSRM y encendía el loading de pantalla. La ruta solo
// necesita recalcularse cuando una parada ya tiene coordenadas nuevas.
const routeStopsSignature = computed(() =>
    stops.value
        .filter((stop) => stop.lat != null && stop.lng != null)
        .map((stop) => `${Number(stop.lat).toFixed(6)},${Number(stop.lng).toFixed(6)}`)
        .join('|')
);

watch(
    [originLat, originLng, destinationLat, destinationLng, routeStopsSignature],
    async () => {
        const requestId = ++routeRequestId;

        if (originLat.value == null || destinationLat.value == null) {
            routeLoading.value = false;
            routeCoords.value = [];
            routeDistanceKm.value = null;
            routeDurationMin.value = null;
            stopLegs.value = [];
            return;
        }

        routeLoading.value = true;

        try {
            const resolvedStops = stops.value.filter((stop) => stop.lat != null);

            if (!resolvedStops.length) {
                const route = await fetchOsrmRoute(originLat.value, originLng.value, destinationLat.value, destinationLng.value);
                if (requestId !== routeRequestId) return;
                routeCoords.value = route.coords;
                routeDistanceKm.value = route.distanceKm;
                routeDurationMin.value = route.durationMin;
                stopLegs.value = [];
                return;
            }

            const points = [
                { lat: originLat.value, lng: originLng.value },
                ...resolvedStops.map((stop) => ({ lat: stop.lat, lng: stop.lng })),
                { lat: destinationLat.value, lng: destinationLng.value },
            ];
            const multiRoute = await fetchOsrmMultiRoute(points);
            if (requestId !== routeRequestId) return;
            routeCoords.value = multiRoute.coords;

            // El último tramo (últimaParada→destino) es el que sigue siendo
            // "el" precio principal — los anteriores son los de las paradas.
            const finalLeg = multiRoute.legs[multiRoute.legs.length - 1] ?? {};
            routeDistanceKm.value = finalLeg.distanceKm ?? null;
            routeDurationMin.value = finalLeg.durationMin ?? null;
            stopLegs.value = multiRoute.legs.slice(0, -1);
        } finally {
            if (requestId === routeRequestId) routeLoading.value = false;
        }
    },
    { immediate: true }
);

// --- Precio sugerido (sección 5): distancia × tarifa de referencia. Es una
// estimación para mostrar antes de mandar la solicitud — el monto que
// realmente queda registrado lo calcula el backend (puede variar un poco por
// el recargo horario). Si se manda "a toda la flota", todavía no hay un
// conductor puntual, así que se usa el promedio de tarifas de la flota.
// Pedido explícito del usuario: "súbele siempre a cada carrera... a los km
// 800 metros más" — el backend ya suma este margen antes de calcular el
// precio (ver App\Services\PriceCalculator::DISTANCE_PADDING_KM). Se replica
// acá SOLO para este estimado (nunca para `routeDistanceKm`, que es lo que
// se manda al backend como distancia real de la ruta) — así el desglose "X
// km × $Y/km" que ve el cliente coincide con lo que de verdad se le va a
// cobrar, en vez de quedar corto.
const DISTANCE_PADDING_KM = 0.8;

// Bug real reportado por el usuario ("sale que un conductor cobra 2.00 y
// cuando pide la carrera sale que es 2.30... el tema de costo debe ser
// transparente"): faltaba replicar acá el recargo nocturno/pico que
// App\Services\PriceCalculator::suggestedPrice() sí aplica al crear la
// solicitud de verdad — mismo criterio de cruce de medianoche que
// isWithinHourRange() en PHP, y nocturno/pico nunca se suman entre sí (gana
// el nocturno). Se evalúa con la hora local del navegador — la app opera en
// una sola zona horaria (Ecuador), igual criterio que el resto de esta
// pantalla.
function isWithinHourRange(hour, start, end) {
    if (start > end) return hour >= start || hour < end;
    return hour >= start && hour < end;
}

function currentTimeSurchargePercent() {
    const t = props.timeSurcharge;
    const hour = new Date().getHours();
    if (isWithinHourRange(hour, t.night_starts_at, t.night_ends_at)) return t.night_percent;
    if (isWithinHourRange(hour, t.peak_morning_starts_at, t.peak_morning_ends_at)) return t.peak_percent;
    if (isWithinHourRange(hour, t.peak_evening_starts_at, t.peak_evening_ends_at)) return t.peak_percent;
    return 0;
}

// Aplica el recargo sobre una base YA decidida (post tarifa mínima) — mismo
// orden que PriceCalculator::suggestedPrice(): primero el máximo con el
// piso, el recargo horario es un % de ESE valor, recién al final se suma
// todo y se redondea hacia arriba a la décima.
function applyTimeSurcharge(base) {
    return Math.round(base * (currentTimeSurchargePercent() / 100) * 100) / 100;
}

// Bug reportado por el usuario ("el km que colocas arriba en el mapa y el
// que colocas abajo... procura que sean iguales"): la tarjeta fija de
// origen/destino (arriba) siempre mostró la distancia real de la ruta —
// este computed es ese mismo número, extraído para reusarlo también en el
// desglose de precio (abajo), que antes mostraba el km CON el margen de
// 0.8 sumado. El margen sigue afectando el precio (ver estimatedDistanceKm
// más abajo), simplemente ya no se le muestra al cliente como si fuera
// parte de la distancia del viaje.
const realDistanceKm = computed(() => {
    if (originLat.value == null || destinationLat.value == null) return null;
    // Se prefiere la distancia REAL de manejo (OSRM, la misma ruta ya
    // dibujada en el mapa) apenas está disponible — la línea recta queda
    // solo como estimación de arranque mientras se calcula, o de respaldo
    // si el servicio de ruteo no responde (ver fetchOsrmRoute()).
    return routeDistanceKm.value ?? distanceKm(originLat.value, originLng.value, destinationLat.value, destinationLng.value);
});

const estimatedDistanceKm = computed(() => {
    if (realDistanceKm.value == null) return null;
    return realDistanceKm.value + DISTANCE_PADDING_KM;
});

// Busca con findDriverById() (no `driversWithDistance`, acotado a la
// categoría desplegada, ni `allCandidates`, que igual lo pierde si deja de
// calzar el filtro de pasajeros/cajuela) — el conductor elegido tiene que
// encontrarse acá sin importar qué tarjeta esté abierta ni qué se haya
// cambiado después en el cajón de opciones.
const referenceRatePerKm = computed(() => {
    if (selectedCooperativeId.value) {
        return Number(selectedCooperativeInfo.value?.effective_rate_per_km ?? selectedCooperativeInfo.value?.average_rate_per_km ?? 0);
    }

    if (selectedDriverId.value !== WHOLE_FLEET) {
        const chosen = selectedDriverInfo.value;
        return chosen ? Number(chosen.rate_per_km ?? 0) : 0;
    }

    // Promedio sobre `allCandidates` (no `driversWithDistance`, acotado a la
    // categoría activa) — bug real encontrado al agregar las categorías: si
    // la categoría abierta era "Cooperativas" (sin lista de conductores
    // propia), driversWithDistance quedaba vacío y el estimado mostraba $0.
    // El promedio de flota+público es la mejor referencia disponible
    // mientras no haya un conductor puntual elegido, sin importar qué
    // tarjeta esté abierta.
    const rates = allCandidates.value.map((driver) => Number(driver.rate_per_km ?? 0)).filter(Boolean);
    return rates.length ? rates.reduce((a, b) => a + b, 0) / rates.length : 0;
});

// Fix reportado por el usuario: si distancia × tarifa da menos que la tarifa
// mínima configurada, el backend cobra el mínimo (PriceCalculator) — acá se
// replica el mismo max(...) para que el estimado no mienta, y se marca
// cuándo se está aplicando el mínimo para ocultar el desglose por km (ya no
// tiene sentido mostrarlo si no es lo que se termina cobrando).
const rawPriceByDistance = computed(() => {
    if (estimatedDistanceKm.value == null) return null;
    // Pedido explícito del usuario: siempre redondeado hacia arriba a los 10
    // centavos, para que lo mostrado acá ya sea el mismo número que termina
    // guardando el backend (ver PriceCalculator::roundUpToDime()).
    return roundUpToDime(estimatedDistanceKm.value * referenceRatePerKm.value);
});

// Pedido explícito del usuario: si el conductor elegido declaró SU PROPIA
// tarifa mínima (y no supera la de la plataforma, ya validado al guardar su
// perfil), el estimado tiene que respetarla en vez de mostrar siempre la
// general — mismo criterio que referenceRatePerKm, pero sin promediar entre
// conductores en "toda la flota" (ver RideRequestController::referenceMinimumFare()).
const referenceMinimumFare = computed(() => {
    if (selectedDriverId.value === WHOLE_FLEET) return props.minimumFare;

    const driverFloor = selectedDriverInfo.value?.minimum_fare != null ? Number(selectedDriverInfo.value.minimum_fare) : null;
    return driverFloor != null ? Math.min(driverFloor, props.minimumFare) : props.minimumFare;
});

const isMinimumFareApplied = computed(() => rawPriceByDistance.value != null && rawPriceByDistance.value < referenceMinimumFare.value);

// Cargo por trayecto de recogida (pedido explícito del usuario: "el precio
// ofertado ya incluye la recogida" — sin checkbox aparte del conductor, el
// precio queda fijo desde acá). Solo se puede anticipar con exactitud cuando
// el cliente eligió un conductor puntual (se conoce su ubicación real); en
// "toda la flota"/cooperativa no se sabe qué candidato le va a tocar, así
// que el backend lo suma después de forma transparente — ver
// RideRequestCreator::create()). Reusa pickupFareEstimateFor() (definida
// más abajo, function declaration con hoisting).
const estimatedPickupFareForSelected = computed(() => {
    if (selectedCooperativeId.value || selectedDriverId.value === WHOLE_FLEET) return 0;
    if (!selectedDriverInfo.value) return 0;
    return pickupFareEstimateFor(selectedDriverInfo.value);
});

// Bug reportado por el usuario ("dice el estimado 7.97 y luego al pedir sale
// 8.00"): el backend redondea hacia arriba a la décima el TOTAL completo
// (viaje + recogida, ver RideRequestCreator::create() con conductor puntual
// elegido) — acá se sumaba la recogida DESPUÉS de redondear solo el viaje,
// dejando el estimado con centavos sueltos que nunca iban a ser el número
// final. roundUpToDime() sobre la suma completa hace que el estimado ya sea
// ese mismo número, sin sorpresas al confirmar.
const estimatedPrice = computed(() => {
    if (rawPriceByDistance.value == null) return null;
    const base = Math.max(rawPriceByDistance.value, referenceMinimumFare.value);
    return roundUpToDime(base + applyTimeSurcharge(base) + estimatedPickupFareForSelected.value);
});

// Paradas adicionales (pedido explícito del usuario: "esto recalcule las
// rutas y los costos... cada parada se calcula diferente e individual") —
// mismo criterio que estimatedPrice (redondeo hacia arriba + tarifa mínima),
// aplicado a cada tramo de stopLegs por separado, espejo del backend.
const stopsWithPrices = computed(() =>
    stops.value
        .filter((stop) => stop.lat != null)
        .map((stop, index) => {
            const legKm = stopLegs.value[index]?.distanceKm;
            if (legKm == null) return { ...stop, price: null };
            const raw = Math.round((legKm + DISTANCE_PADDING_KM) * referenceRatePerKm.value * 100) / 100;
            const base = Math.max(raw, referenceMinimumFare.value);
            return { ...stop, distanceKm: legKm, price: roundUpToDime(base + applyTimeSurcharge(base)) };
        })
);

const stopsTotalPrice = computed(() => {
    const prices = stopsWithPrices.value.map((stop) => stop.price).filter((price) => price != null);
    return prices.length ? roundUpToDime(prices.reduce((a, b) => a + b, 0)) : null;
});

// Cargo por trayecto de recogida (pedido explícito del usuario: "al cliente
// no le pongas ese texto tan extenso... si no dejarle claro que en el costo
// total está el costo por recogida tan extensa"): el aviso solo aparece
// cuando el estimado de arriba realmente incluye algo — reusa el mismo
// computed que ya suma el cargo al precio, para que el número y el aviso
// nunca queden desincronizados entre sí.
const showsPickupSurchargeNotice = computed(() => estimatedPickupFareForSelected.value > 0);

// Precio total del itinerario completo (pedido explícito del usuario) —
// paradas + tramo final, mismo total que terminará guardando el backend
// (Ride.stops_price + Ride.price).
const estimatedTotalPrice = computed(() => {
    if (estimatedPrice.value == null) return null;
    return estimatedPrice.value + (stopsTotalPrice.value ?? 0);
});

// Cargo por trayecto de recogida (pedido explícito del usuario: "debe bajar
// un costo porque la recogida es más cerca" — al invertir origen/destino, la
// distancia real de CADA conductor hasta el nuevo origen cambia, y el precio
// mostrado tiene que reflejarlo). `driver.distance` ya es esa distancia
// (Haversine origin↔conductor, calculada en withDistanceAndFilters/
// selectedDriverInfo) — acá solo se replica
// App\Services\PriceCalculator::pickupSurcharge() con esos datos.
function pickupFareEstimateFor(driver) {
    if (driver.distance == null || !driver.pickup_surcharge_enabled) return 0;
    if (driver.distance <= props.pickupSurchargeThresholdKm) return 0;

    const rate = Number(driver.rate_per_km ?? 0);
    return Math.round(driver.distance * rate * (props.pickupSurchargePercent / 100) * 100) / 100;
}

// Precio estimado POR CONDUCTOR (rediseño UX, con mockup de referencia: cada
// fila de la lista muestra su propio precio, no solo el del elegido) — mismo
// cálculo que rawPriceByDistance/referenceMinimumFare de acá arriba, aplicado
// a un conductor puntual en vez de al elegido o al promedio de la flota. Ya
// incluye el estimado de recogida de ESE conductor (de acá arriba), para que
// dos conductores con la misma tarifa no se vean igual de "baratos" si uno
// está mucho más lejos del origen que el otro.
function estimatedPriceForDriver(driver) {
    if (estimatedDistanceKm.value == null) return null;
    const raw = roundUpToDime(estimatedDistanceKm.value * Number(driver.rate_per_km ?? 0));
    const floor = driver.minimum_fare != null ? Math.min(Number(driver.minimum_fare), props.minimumFare) : props.minimumFare;
    const base = Math.max(raw, floor);
    // Mismo fix que estimatedPrice de acá arriba: redondear hacia arriba el
    // TOTAL (viaje + recargo horario + recogida), no solo el viaje, para que
    // este número ya sea el mismo que el cliente va a ver después al elegir
    // a este conductor.
    return roundUpToDime(base + applyTimeSurcharge(base) + pickupFareEstimateFor(driver));
}

function estimatedStopsPriceForDriver(driver) {
    const rate = Number(driver.rate_per_km ?? 0);
    const floor = driver.minimum_fare != null ? Math.min(Number(driver.minimum_fare), props.minimumFare) : props.minimumFare;
    const prices = stopLegs.value
        .map((leg) => leg?.distanceKm)
        .filter((distance) => distance != null)
        .map((distance) => {
            const base = Math.max(Math.round((distance + DISTANCE_PADDING_KM) * rate * 100) / 100, floor);
            return roundUpToDime(base + applyTimeSurcharge(base));
        });

    return prices.length ? roundUpToDime(prices.reduce((total, price) => total + price, 0)) : 0;
}

function estimatedTotalPriceForDriver(driver) {
    const finalLegPrice = estimatedPriceForDriver(driver);
    if (finalLegPrice == null) return null;

    return roundUpToDime(finalLegPrice + estimatedStopsPriceForDriver(driver));
}

// Valor orientativo de cada grupo para la ruta actual. Se muestra como
// "Desde" porque representa la alternativa disponible más económica, no una
// promesa de precio final. En cooperativas todavía no se conoce la unidad que
// será asignada, por eso se usa la tarifa promedio declarada por cada una.
function estimatedTotalPriceForCooperative(cooperative) {
    if (estimatedDistanceKm.value == null) return null;
    const rate = Number(cooperative.effective_rate_per_km ?? cooperative.average_rate_per_km ?? 0);
    if (!rate) return null;

    const base = Math.max(roundUpToDime(estimatedDistanceKm.value * rate), props.minimumFare);
    const stopPrices = stopLegs.value
        .map((leg) => leg?.distanceKm)
        .filter((distance) => distance != null)
        .map((distance) => {
            const stopBase = Math.max(Math.round((distance + DISTANCE_PADDING_KM) * rate * 100) / 100, props.minimumFare);
            return roundUpToDime(stopBase + applyTimeSurcharge(stopBase));
        });

    return roundUpToDime(base + applyTimeSurcharge(base) + stopPrices.reduce((total, price) => total + price, 0));
}

// Recomendación automática: primero el menor total estimado para la ruta y,
// cuando dos opciones cuestan lo mismo, la más cercana al punto de recogida.
// Si todavía no hay ruta/tarifa suficiente, la cercanía decide por sí sola.
const recommendedCooperative = computed(() => {
    return [...props.cooperatives].sort((left, right) => {
        const leftPrice = estimatedTotalPriceForCooperative(left) ?? Number.POSITIVE_INFINITY;
        const rightPrice = estimatedTotalPriceForCooperative(right) ?? Number.POSITIVE_INFINITY;
        if (leftPrice !== rightPrice) return leftPrice - rightPrice;

        const leftDistance = Number(left.distance_km ?? Number.POSITIVE_INFINITY);
        const rightDistance = Number(right.distance_km ?? Number.POSITIVE_INFINITY);
        return leftDistance - rightDistance;
    })[0] ?? null;
});

watch(recommendedCooperative, (cooperative) => {
    if (activeCategory.value === 'cooperative' && !selectedCooperativeId.value) {
        selectedCooperativeId.value = cooperative?.id ?? null;
    }
}, { immediate: true });

const categoryStartingPrices = computed(() => {
    const lowestDriverPrice = (drivers) => {
        const prices = drivers
            .map(estimatedTotalPriceForDriver)
            .filter((price) => Number.isFinite(price) && price > 0);

        return prices.length ? Math.min(...prices) : null;
    };

    const cooperativePrices = props.cooperatives
        .map(estimatedTotalPriceForCooperative)
        .filter((price) => Number.isFinite(price) && price > 0);

    return {
        fleet: lowestDriverPrice(fleetCandidates.value),
        cooperative: cooperativePrices.length ? Math.min(...cooperativePrices) : null,
        public: lowestDriverPrice(publicCandidates.value),
        all: lowestDriverPrice(allCandidates.value),
    };
});

function formattedStartingPrice(category) {
    const price = categoryStartingPrices.value[category];
    return price == null ? null : `$${price.toFixed(2)}`;
}

// El cliente puede aceptar el precio estimado tal cual, o proponer otro monto
// desde el arranque (sección 5: "el cliente puede aceptar ese precio o hacer
// una contraoferta con otro monto").
const useCustomPrice = ref(false);
const customPrice = ref(null);

const form = useForm({
    fleet_id: props.fleet.id,
    provider_type: 'driver',
    cooperative_id: null,
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
    // Paradas adicionales (pedido explícito del usuario) — se arma en
    // submit(), ver stops (ref) más arriba.
    stops: [],
    // Distancia real de manejo (OSRM), no la línea recta — pedido explícito
    // del usuario: "probá el mapa... en temas de km" (ver el bug real
    // documentado arriba, en routeDistanceKm). El backend la usa si es
    // razonable, y cae de vuelta a Haversine si no llegó o no cierra.
    route_distance_km: null,
    offered_price: null,
    is_scheduled: false,
    scheduled_date: '',
    scheduled_time: '',
    round_trip: false,
    passenger_count: 1,
    needs_trunk: false,
    payment_method: 'efectivo',
    notes: '',
});

// Si "toda mi flota" (o la categoría que esté abierta) no tiene a quién
// ofrecerle la carrera, se salta SOLO a la siguiente categoría con
// candidatos — pedido explícito del usuario ("esto deberia ser automatico...
// la idea es evitar hacer tantos click"): antes, si ya había una categoría
// elegida (ej. el cliente ya estaba en "Mi flota"), el aviso de error se
// quedaba ahí mismo mostrando un botón "Pruebe con X" que había que tocar
// aparte — el salto automático solo pasaba si NINGUNA categoría estaba
// abierta todavía. `driverErrorFallbackLabel` es lo que el aviso de abajo
// (paso 'driver') usa para explicar el salto, en vez de pedir otro clic.
const driverErrorFallbackLabel = ref('');
watch(
    () => form.errors.driver_user_id,
    (error) => {
        if (!error) {
            driverErrorFallbackLabel.value = '';
            return;
        }
        step.value = 'driver';
        const fallback = activeCategory.value ? nextNonEmptyCategory(activeCategory.value) : recommendCategory();
        if (fallback && fallback !== activeCategory.value) {
            activeCategory.value = fallback;
            driverErrorFallbackLabel.value = CATEGORY_META[fallback].label.toLowerCase();
        } else {
            driverErrorFallbackLabel.value = '';
        }
    }
);

// Elegir una tarjeta de categoría a mano (pedido explícito del usuario, en
// el mismo pedido de arriba): limpia el aviso de "ningún conductor
// conectado" de un intento anterior — sin esto, ese aviso (con la sugerencia
// de otra categoría que ya no aplica) se quedaba pegado en pantalla aunque
// el cliente ya hubiera elegido otra cosa a mano.
function selectCategory(category) {
    activeCategory.value = activeCategory.value === category ? null : category;
    driverErrorFallbackLabel.value = '';
    form.clearErrors('driver_user_id', 'cooperative_id');
}

// "Ahora mismo" (default) o "programada" para una fecha/hora futura, con la
// opción de ida y vuelta (consideración agregada al alcance, pedido
// explícito del usuario). Fecha mínima seleccionable: hoy.
const whenMode = ref(props.startScheduled ? 'scheduled' : 'now');
const todayDateString = new Date().toISOString().slice(0, 10);
const scheduledDate = ref('');
// Bug real reportado por el usuario, con captura: el reloj nativo del
// navegador (`<input type="time">`) confundía a la gente — pedía las 6:40
// a. m. y terminaba mandando otra cosa sin darse cuenta, arrastrando dedos
// sobre el selector tipo manecillas. Tres <select> explícitos (hora en
// formato 12h, minutos, a. m./p. m.) son imposibles de "tocar mal" por
// error — mismo criterio de claridad que el resto del formulario.
const scheduledHour = ref('');
const scheduledMinute = ref('');
const scheduledPeriod = ref('AM');
const HOUR_OPTIONS = Array.from({ length: 12 }, (_, i) => String(i + 1));
const MINUTE_OPTIONS = Array.from({ length: 12 }, (_, i) => String(i * 5).padStart(2, '0'));
const scheduledTime = computed(() => {
    if (!scheduledHour.value || scheduledMinute.value === '') return '';
    let hour24 = Number(scheduledHour.value) % 12;
    if (scheduledPeriod.value === 'PM') hour24 += 12;
    return `${String(hour24).padStart(2, '0')}:${scheduledMinute.value}`;
});
const roundTrip = ref(false);
// Observación libre para el conductor (pedido explícito del usuario: "que
// exista un campo que el cliente meta una observación que no sea
// obligatoria") — nunca requerida.
const scheduledNotes = ref('');
// Forma de pago (pedido explícito del usuario): "efectivo" de default,
// el cliente todavía no tenía ninguna forma de elegirla.
const paymentMethod = ref(props.initialOptions.payment_method ?? 'efectivo');

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

// Gate del botón "Continuar" del paso 'destination' (rediseño UX): origen y
// destino son los únicos datos realmente obligatorios para avanzar — mismo
// criterio que ya exige el backend en RideRequestController::store()
// (origin_lat/lng y destination_lat/lng son los únicos `required`). Si ya
// se eligió "Programar viaje", la fecha/hora también se piden acá (es la
// sección que las muestra), para no dejar avanzar a elegir conductor sin
// saber para cuándo es.
const canProceedToDriver = computed(() => {
    if (originLat.value == null || destinationLat.value == null) return false;
    if (whenMode.value === 'scheduled') return Boolean(scheduledDate.value && scheduledTime.value);
    return true;
});

function goToDriverStep() {
    step.value = 'driver';
}

function backToDestinationStep() {
    step.value = 'destination';
    activeCategory.value = null;
}

// Pedido explícito del usuario: invertir origen y destino con un solo botón,
// junto a "Cambiar" — el watch de [originLat, originLng, destinationLat,
// destinationLng] de más abajo ya recalcula ruta/distancia/precio solo en
// cuanto cambian estos refs, así que acá alcanza con intercambiarlos.
function swapOriginDestination() {
    [originLat.value, destinationLat.value] = [destinationLat.value, originLat.value];
    [originLng.value, destinationLng.value] = [destinationLng.value, originLng.value];
    [originAddress.value, destinationAddress.value] = [destinationAddress.value, originAddress.value];
    [originSectorId.value, destinationSectorId.value] = [destinationSectorId.value, originSectorId.value];
    // Paradas adicionales (pedido explícito del usuario): si hay paradas, el
    // itinerario invertido tiene que recorrerlas en el orden contrario para
    // seguir teniendo sentido.
    stops.value = [...stops.value].reverse();
}

// Bug real reportado por el usuario ("pedí una ruta con varias paradas y no
// funcionó"): al escribir una parada a mano sin tocar ninguna sugerencia del
// autocompletado, `stop.lat` se quedaba en null — el submit() de más abajo
// la filtraba en silencio (`stops.value.filter(stop => stop.lat != null)`),
// así que la carrera se creaba igual, pero como si esa parada nunca hubiera
// existido, sin avisar nada. canSubmit ahora exige que toda parada agregada
// tenga coordenadas reales antes de dejar pedir la carrera.
const hasUnresolvedStop = computed(() => stops.value.some((stop) => stop.lat == null));

const canSubmit = computed(() => {
    if (originLat.value == null || destinationLat.value == null) return false;
    if (hasUnresolvedStop.value) return false;
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
    const cooperativeId = activeCategory.value === 'cooperative'
        ? (selectedCooperativeId.value ?? recommendedCooperative.value?.id ?? null)
        : null;

    if (activeCategory.value === 'cooperative' && !cooperativeId) {
        form.setError('cooperative_id', 'No hay una cooperativa disponible para esta ruta.');
        step.value = 'driver';
        return;
    }

    form.fleet_id = props.fleet.id;
    form.provider_type = cooperativeId ? 'cooperative' : 'driver';
    form.cooperative_id = cooperativeId;
    form.driver_user_id = cooperativeId ? null : selectedDriverId.value;
    // También cuando el cliente elige a alguien puntual conservamos la bolsa
    // elegida como respaldo: ese conductor recibe la primera oportunidad y,
    // si no responde, el mismo precio pasa al siguiente candidato elegible.
    form.dispatch_pool = cooperativeId || whenMode.value === 'scheduled' ? null : sourceMode.value;
    form.origin_lat = originLat.value;
    form.origin_lng = originLng.value;
    form.origin_address = originAddress.value;
    form.origin_sector_id = originSectorId.value;
    form.destination_lat = destinationLat.value;
    form.destination_lng = destinationLng.value;
    form.destination_address = destinationAddress.value;
    form.destination_sector_id = destinationSectorId.value;
    // Paradas adicionales (pedido explícito del usuario) — misma distancia
    // real por tramo que ya calculó stopLegs vía OSRM, para que el backend
    // no tenga que confiar solo en la línea recta.
    form.stops = stops.value
        .filter((stop) => stop.lat != null)
        .map((stop, index) => ({
            lat: stop.lat,
            lng: stop.lng,
            address: stop.address || null,
            sector_id: stop.sectorId,
            route_distance_km: stopLegs.value[index]?.distanceKm ?? null,
        }));
    form.route_distance_km = routeDistanceKm.value;
    form.offered_price = useCustomPrice.value ? customPrice.value : null;
    form.is_scheduled = whenMode.value === 'scheduled';
    form.scheduled_date = whenMode.value === 'scheduled' ? scheduledDate.value : '';
    form.scheduled_time = whenMode.value === 'scheduled' ? scheduledTime.value : '';
    form.round_trip = roundTrip.value;
    form.notes = whenMode.value === 'scheduled' ? scheduledNotes.value.trim() || null : null;
    form.passenger_count = passengerCount.value;
    form.needs_trunk = needsTrunk.value;
    form.payment_method = paymentMethod.value;

    form.post(route('ride-requests.store'));
}
</script>

<template>
    <Head title="Solicitar carrera" />

    <AuthenticatedLayout
        :style="step === 'destination' ? { background: destinationBackground } : null"
    >
        <!-- El título de página solo aparece en el paso 1 (bug real reportado por
             el usuario, con captura: en los pasos 2 y 3 el mapa "no aprovechaba
             la parte de arriba" y obligaba a scrollear para ver "Elige tu
             conductor" completo) — mismo criterio que ya usa Dashboard.vue para
             su pantalla de mapa, que tampoco define este slot. -->
        <!-- Fondo propio del paso inicial: verde bosque en vez de negro puro.
             Mantiene el lenguaje oscuro de Arka01, pero conecta visualmente
             con el acento menta y con la superficie clara del formulario. -->
        <div
            :class="step === 'destination' ? 'min-h-[calc(100dvh-4rem)] py-3 pb-24 sm:py-8' : 'py-3'"
            :style="step === 'destination'
                ? { background: destinationBackground }
                : null"
        >
            <div
                class="mx-auto px-3 sm:px-6 lg:px-8"
                :class="step === 'destination' ? 'max-w-xl' : 'max-w-3xl space-y-6'"
            >
                <!-- Rediseño UX (pedido explícito del usuario, guiado por
                     ARKA01_Rediseno_UX_Flujo_Carreras.md): paso 1, "¿A dónde
                     vas?" — todo lo que hace falta para tener origen y
                     destino, tal cual estaba, ahora envuelto en su propio
                     paso en vez de ser el arranque de un scroll largo. -->
                <template v-if="step === 'destination'">
                <ArkaRouteLoader :show="destinationLoading" :title="destinationLoadingTitle" />
                <div class="flex flex-col overflow-hidden rounded-[26px] border border-white/70 bg-[#f4f7f5] shadow-[0_24px_70px_rgba(1,12,7,0.30)] ring-1 ring-arka-primary/[0.06]">
                <div class="order-1 flex items-center justify-between gap-4 bg-white/95 px-4 py-3.5 sm:px-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-arka-primary">
                            {{ whenMode === 'scheduled' ? 'Viaje programado' : 'Nueva carrera' }}
                        </p>
                        <h1 class="mt-0.5 text-[22px] font-bold tracking-tight text-arka-base">
                            {{ whenMode === 'scheduled' ? 'Programa tu viaje' : '¿A dónde vamos?' }}
                        </h1>
                        <p class="mt-0.5 text-xs text-arka-base/45">Revisa el punto de partida y el destino.</p>
                    </div>
                    <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-arka-base/[0.06] bg-[#f6f8f7] text-arka-base/55 shadow-sm" aria-label="Volver al inicio" @click="router.visit(route('dashboard'))">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                        </svg>
                    </button>
                </div>
                <!-- Selector de flota: solo aparece si el cliente tiene más de una
                     (sección 7.3, plan Multi-flota). -->
                <div v-if="fleets.length > 1" class="order-6 mx-3 mt-3 rounded-2xl border border-arka-base/[0.05] bg-white p-4 shadow-sm sm:mx-4">
                    <InputLabel value="Pedir carrera desde la flota" light />
                    <SearchableSelect
                        class="mt-1"
                        light
                        :model-value="fleet.id"
                        :options="fleets.map((f) => ({ value: f.id, label: f.name }))"
                        @update:model-value="changeFleet"
                    />
                </div>

                <div v-if="locationError" class="order-4 mx-3 mt-3 rounded-2xl border border-arka-warning/25 bg-arka-warning/10 p-3 text-sm text-arka-base/70 sm:mx-4">
                    {{ locationError }}
                </div>

                <!-- ¿Cuándo? (consideración agregada al alcance, pedido explícito del
                     usuario): "ahora mismo" por defecto, o programar fecha/hora — con
                     la opción de marcarla como ida y vuelta. -->
                <div class="order-5 mx-3 mt-3 space-y-3 rounded-2xl border border-arka-base/[0.05] bg-white p-3 shadow-sm sm:mx-4 sm:p-4">
                    <p class="text-xs font-semibold text-arka-base/55">¿Cuándo viajas?</p>

                    <div class="grid grid-cols-2 gap-1 rounded-full bg-arka-base/[0.05] p-1 text-sm">
                        <button
                            type="button"
                            class="px-3 py-2 rounded-full font-medium transition"
                            :class="whenMode === 'now' ? 'bg-white text-arka-base shadow-sm' : 'text-arka-base/45'"
                            @click="whenMode = 'now'"
                        >
                            Ahora mismo
                        </button>
                        <button
                            type="button"
                            class="px-3 py-2 rounded-full font-medium transition"
                            :class="whenMode === 'scheduled' ? 'bg-white text-arka-base shadow-sm' : 'text-arka-base/45'"
                            @click="whenMode = 'scheduled'"
                        >
                            Programar viaje
                        </button>
                    </div>

                    <div v-if="whenMode === 'scheduled'" class="space-y-3 pt-1">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <InputLabel for="scheduled_date" value="Fecha" light />
                                <TextInput
                                    id="scheduled_date"
                                    type="date"
                                    class="mt-1 block w-full"
                                    :min="todayDateString"
                                    v-model="scheduledDate"
                                    light
                                />
                                <InputError class="mt-1" :message="form.errors.scheduled_date" />
                            </div>
                            <div>
                                <InputLabel value="Hora" light />
                                <div class="mt-1 grid grid-cols-3 gap-2">
                                    <select
                                        v-model="scheduledHour"
                                        aria-label="Hora"
                                        class="w-full rounded-arka border-arka-base/10 bg-white text-arka-base shadow-sm focus:border-arka-primary focus:ring-arka-primary"
                                    >
                                        <option value="" disabled>Hora</option>
                                        <option v-for="hour in HOUR_OPTIONS" :key="hour" :value="hour">{{ hour }}</option>
                                    </select>
                                    <select
                                        v-model="scheduledMinute"
                                        aria-label="Minutos"
                                        class="w-full rounded-arka border-arka-base/10 bg-white text-arka-base shadow-sm focus:border-arka-primary focus:ring-arka-primary"
                                    >
                                        <option value="" disabled>Min.</option>
                                        <option v-for="minute in MINUTE_OPTIONS" :key="minute" :value="minute">{{ minute }}</option>
                                    </select>
                                    <select
                                        v-model="scheduledPeriod"
                                        aria-label="A. m. o p. m."
                                        class="w-full rounded-arka border-arka-base/10 bg-white text-arka-base shadow-sm focus:border-arka-primary focus:ring-arka-primary"
                                    >
                                        <option value="AM">a. m.</option>
                                        <option value="PM">p. m.</option>
                                    </select>
                                </div>
                                <InputError class="mt-1" :message="form.errors.scheduled_time" />
                            </div>
                        </div>

                        <label class="flex items-center gap-2">
                            <input type="checkbox" v-model="roundTrip" class="text-arka-primary rounded" />
                            <span class="text-sm font-medium text-arka-base/75">Es ida y vuelta</span>
                        </label>

                        <!-- Observación libre (pedido explícito del usuario): algo que el
                             conductor tenga que saber de antemano — nunca obligatoria. -->
                        <div>
                            <InputLabel value="Observación para el conductor (opcional)" light />
                            <textarea
                                v-model="scheduledNotes"
                                rows="2"
                                maxlength="500"
                                class="mt-1 block w-full rounded-arka border-arka-base/10 bg-white text-arka-base placeholder:text-arka-base/35 shadow-sm focus:border-arka-primary focus:ring-arka-primary"
                                placeholder="Ej: el portón es el azul, llamar al llegar…"
                            ></textarea>
                            <InputError class="mt-1" :message="form.errors.notes" />
                        </div>
                    </div>
                </div>

                <!-- Forma de pago (bug real reportado por el usuario: "en esta
                     pantalla no aparece el tipo de pago" — antes solo se podía
                     elegir en el cajón "Opciones del viaje" del paso "Elige tu
                     conductor", fácil de no abrir nunca). Mismo ref
                     `paymentMethod` que ya usa ese paso más abajo — es la
                     misma decisión, ahora también visible y editable acá,
                     junto a "¿Cuándo viajas?", el mismo tipo de decisión
                     temprana. `order-5`, mismo valor que el bloque de arriba
                     — entre los dos solo importa el orden real del DOM. -->
                <div class="order-5 mx-3 mt-3 space-y-3 rounded-2xl border border-arka-base/[0.05] bg-white p-3 shadow-sm sm:mx-4 sm:p-4">
                    <p class="text-xs font-semibold text-arka-base/55">Forma de pago</p>

                    <div class="grid grid-cols-2 gap-1 rounded-full bg-arka-base/[0.05] p-1 text-sm">
                        <button
                            type="button"
                            class="px-3 py-2 rounded-full font-medium transition"
                            :class="paymentMethod === 'efectivo' ? 'bg-white text-arka-base shadow-sm' : 'text-arka-base/45'"
                            @click="paymentMethod = 'efectivo'"
                        >
                            Efectivo
                        </button>
                        <button
                            type="button"
                            class="px-3 py-2 rounded-full font-medium transition"
                            :class="paymentMethod === 'transferencia' ? 'bg-white text-arka-base shadow-sm' : 'text-arka-base/45'"
                            @click="paymentMethod = 'transferencia'"
                        >
                            Transferencia
                        </button>
                    </div>
                </div>

                <!-- Origen/Destino (pedido explícito del usuario: "simplificar la
                     búsqueda... que sea más fácil pedir una carrera") — buscador con
                     Google Places como campo principal, con los lugares ya usados
                     antes como favoritos (ver AddressAutocomplete.vue). -->
                <div class="order-3 relative z-10 mx-3 -mt-7 space-y-3 rounded-[22px] border border-arka-base/[0.05] bg-white p-4 shadow-[0_14px_35px_rgba(1,12,7,0.16)] sm:mx-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-bold text-arka-base">Direcciones</h3>
                            <p class="text-xs text-arka-base/40">Puede escribirlas o moverlas en el mapa.</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-[#ecfbf5] px-2.5 py-1 text-[11px] font-semibold text-arka-primary">Paso 1 de 3</span>
                    </div>

                    <!-- "Mis rutas" (pedido explícito del usuario): tomar una
                         ruta guardada de una, sin escribir ni marcar nada. -->
                    <div v-if="savedRoutes.length" class="flex gap-2 overflow-x-auto pb-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        <div
                            v-for="saved in savedRoutes"
                            :key="saved.id"
                            class="group flex shrink-0 items-center gap-1.5 rounded-full border border-arka-base/10 bg-[#f7f8fa] py-1.5 pl-3 pr-1.5 hover:border-arka-primary/50"
                        >
                            <button type="button" class="text-sm text-arka-base" @click="useSavedRoute(saved)">
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

                    <div class="relative pl-6">
                        <span class="absolute left-[5px] top-7 h-[calc(100%+1.75rem)] w-px bg-arka-base/12" aria-hidden="true"></span>
                        <span class="absolute left-0 top-7 h-3 w-3 rounded-full border-[3px] border-arka-primary bg-white" aria-hidden="true"></span>
                        <div class="flex items-center justify-between gap-2">
                            <InputLabel for="origin_address" value="Origen" light />
                            <!-- Pedido explícito del usuario: que el origen también
                                 tenga la opción de usar la ubicación actual, no solo
                                 el intento automático silencioso de al abrir la
                                 pantalla (por si falló, o si el cliente se movió). -->
                            <button
                                type="button"
                                class="flex shrink-0 items-center gap-1 text-[11px] font-medium text-arka-primary hover:text-arka-primary-bright"
                                :disabled="locatingOrigin"
                                @click="() => useCurrentLocationAsOrigin()"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="3" />
                                    <path stroke-linecap="round" d="M12 2v3M12 19v3M2 12h3M19 12h3" />
                                </svg>
                                {{ locatingOrigin ? 'Ubicándote…' : 'Mi ubicación' }}
                            </button>
                        </div>
                        <AddressAutocomplete
                            id="origin_address"
                            class="mt-1"
                            v-model="originAddress"
                            :city-bias="cityBias"
                            :favorites="frequentPlaces"
                            placeholder="Su ubicación actual o busque una dirección"
                            light
                            @place-selected="pickOriginFromAddress"
                            @clear="clearOrigin"
                        />
                    </div>

                    <!-- Paradas adicionales (pedido explícito del usuario:
                         "agregar una parada adicional... hasta 4 paradas",
                         cada una cobrada por separado) — entre origen y
                         destino, mismo AddressAutocomplete reusado. -->
                    <div v-for="(stop, index) in stops" :key="index" class="relative pl-7">
                        <!-- La numeración coincide con el punto intermedio del
                             mapa para reconocer cada parada inmediatamente. -->
                        <span
                            class="absolute -left-0.5 top-8 flex h-5 w-5 items-center justify-center rounded-full bg-amber-600 text-[10px] font-bold leading-none text-white ring-4 ring-amber-50 shadow-sm"
                            aria-hidden="true"
                        >{{ index + 1 }}</span>
                        <div class="flex items-center justify-between gap-2">
                            <InputLabel :for="`stop_address_${index}`" :value="`Parada ${index + 1}`" light />
                            <button type="button" class="text-xs text-arka-danger hover:underline shrink-0" @click="removeStop(index)">Quitar</button>
                        </div>
                        <AddressAutocomplete
                            :id="`stop_address_${index}`"
                            class="mt-1"
                            v-model="stop.address"
                            :city-bias="cityBias"
                            :favorites="frequentPlaces"
                            placeholder="¿Por dónde pasa antes de llegar?"
                            light
                            @place-selected="(place) => pickStopFromAddress(index, place)"
                            @clear="clearStop(index)"
                        />
                        <!-- Bug real reportado por el usuario: escribir la
                             dirección a mano sin elegir una sugerencia dejaba
                             esta parada sin coordenadas, y se descartaba sola
                             al pedir la carrera sin ningún aviso. -->
                        <p v-if="stop.lat == null" class="mt-1 text-xs text-arka-warning">
                            {{ stop.address ? 'Elegí una sugerencia de la lista para fijar esta parada en el mapa — no alcanza con escribir la dirección.' : 'Completá esta parada o quitala con "Quitar".' }}
                        </p>
                    </div>
                    <button
                        v-if="stops.length < MAX_STOPS"
                        type="button"
                        class="pl-7 text-xs font-semibold text-arka-primary hover:text-arka-primary-bright"
                        @click="addStop"
                    >
                        + Agregar parada
                    </button>

                    <div class="relative pl-6">
                        <span class="absolute left-0 top-7 h-3 w-3 rounded-sm bg-rose-500 ring-4 ring-rose-50" aria-hidden="true"></span>
                        <InputLabel for="destination_address" value="Destino" light />
                        <AddressAutocomplete
                            id="destination_address"
                            class="mt-1"
                            v-model="destinationAddress"
                            :city-bias="cityBias"
                            :favorites="frequentPlaces"
                            placeholder="¿A dónde vas?"
                            light
                            @place-selected="pickDestinationFromAddress"
                            @selection-loading="resolvingDestinationSelection = $event"
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
                            <span class="text-sm text-arka-base/75">Guardar esta ruta en "Mis rutas"</span>
                        </label>
                        <div v-if="wantsToSaveRoute" class="mt-2 flex gap-2">
                            <TextInput
                                type="text"
                                class="block w-full"
                                v-model="routeAlias"
                                placeholder="Alias (opcional): Casa, Trabajo, Paseo…"
                                maxlength="50"
                                light
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
                        class="text-xs font-medium text-arka-primary hover:text-arka-primary-bright"
                        @click="showZoneDetails = !showZoneDetails"
                    >
                        {{ showZoneDetails ? 'Ocultar' : 'Precisar ciudad/sector (opcional)' }}
                    </button>

                    <div v-if="showZoneDetails" class="space-y-3 pt-1">
                        <div>
                            <InputLabel value="Ciudad" light />
                            <SearchableSelect
                                class="mt-1"
                                light
                                :model-value="selectedCityId"
                                :options="cityOptions"
                                @update:model-value="changeCity"
                            />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <InputLabel for="origin_sector_id" value="Sector donde está" light />
                                <SearchableSelect
                                    id="origin_sector_id"
                                    class="mt-1"
                                    light
                                    v-model="originSectorId"
                                    :options="sectorOptions"
                                    empty-label="Sin especificar"
                                />
                            </div>
                            <div>
                                <InputLabel for="destination_sector_id" value="Sector a donde vas" light />
                                <SearchableSelect
                                    id="destination_sector_id"
                                    class="mt-1"
                                    light
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
                <div class="order-2 relative min-h-[285px] overflow-hidden border-y border-arka-base/[0.05] bg-white">
                    <FleetMap
                        :markers="mapMarkers"
                        :center="mapCenter ?? undefined"
                        :route="routeCoords"
                        animate-route
                        :clickable="true"
                        :auto-fit="true"
                        :fit-marker-ids="routeFitMarkerIds"
                        :dark="false"
                        :minimal-style="true"
                        origin-marker-style="dot"
                        destination-marker-style="dot"
                        height="285px"
                        @map-click="pickRoutePoint"
                    />
                    <div class="absolute right-3 top-3 z-[500] flex rounded-full border border-white/80 bg-white/90 p-1 shadow-lg backdrop-blur">
                        <button type="button" class="rounded-full px-3 py-1.5 text-[11px] font-semibold transition" :class="mapEditingPoint === 'origin' ? 'bg-arka-primary text-arka-base shadow-sm' : 'text-arka-base/55'" @click="mapEditingPoint = 'origin'">
                            <span class="mr-1 inline-block h-2 w-2 rounded-full bg-current"></span> Origen
                        </button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[11px] font-semibold transition" :class="mapEditingPoint === 'destination' ? 'bg-rose-500 text-white shadow-sm' : 'text-arka-base/55'" @click="mapEditingPoint = 'destination'">
                            <span class="mr-1 inline-block h-2 w-2 rounded-sm bg-current"></span> Destino
                        </button>
                    </div>
                    <div class="absolute bottom-10 left-1/2 z-[500] -translate-x-1/2 whitespace-nowrap rounded-full bg-arka-base/85 px-3 py-1.5 text-[11px] font-medium text-white shadow-lg backdrop-blur">
                        Toca para mover el {{ mapEditingPoint === 'origin' ? 'origen' : 'destino' }}
                    </div>
                </div>

                <!-- Acción 1 del documento: elegido el destino, "Continuar"
                     lleva al paso de elegir conductor — nada de formulario
                     largo en el medio (sección 5). -->
                <div class="order-7 mx-3 pb-4 pt-3 sm:mx-4">
                    <!-- Secuencia visible (pedido explícito del usuario): deja
                         claro que Continuar NO envía todavía la solicitud, sino
                         que abre el siguiente paso para elegir conductor. -->
                    <PrimaryButton class="min-h-12 w-full justify-between text-sm" :disabled="!canProceedToDriver || destinationLoading" @click="goToDriverStep">
                        <span class="flex-1 text-center">{{ destinationLoading ? 'Preparando recorrido…' : 'Continuar y elegir conductor' }}</span>
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-arka-base/10" aria-hidden="true">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                            </svg>
                        </span>
                    </PrimaryButton>
                </div>
                </div>
                </template>

                <!-- Info persistente (sección 32 del documento): mapa fijo arriba
                     con la tarjeta de origen/destino superpuesta encima —
                     pedido explícito del usuario, tal como el bosquejo — en
                     vez de una tarjeta plana separada del mapa. Visible en
                     los pasos 2 y 3, nunca hay que "acordarse" qué se eligió. -->
                <div v-if="step === 'driver' || step === 'confirm'" class="-mx-4 -mt-3 sm:-mx-6 lg:mx-0 lg:mt-0">
                    <!-- Mapa claro (pedido explícito del usuario: "manejemos el
                         mismo color" que Inicio) — la tarjeta de origen/destino
                         se queda oscura a propósito, flotando encima, tal como
                         el bosquejo de referencia. -->
                    <FleetMap
                        :markers="mapMarkers"
                        :center="mapCenter ?? undefined"
                        :route="routeCoords"
                        animate-route
                        :clickable="false"
                        :auto-fit="true"
                        :fit-marker-ids="routeFitMarkerIds"
                        :fit-padding-top="36"
                        :fit-padding-bottom="76"
                        :dark="false"
                        :rounded="false"
                        :minimal-style="true"
                        origin-marker-style="dot"
                        destination-marker-style="dot"
                        height="340px"
                    />
                    <!-- Bug real reportado por el usuario, con varias capturas
                         repetidas ("la tarjeta sigue apareciendo por debajo
                         del mapa"): un intento anterior lo había atribuido a
                         un problema de "pintado" de Leaflet que ignoraba el
                         z-index, y por eso el solape se había dejado en solo
                         12px como parche — pero la causa real es otra:
                         Leaflet le pone z-index PROPIO a sus capas internas
                         (marcadores 600, controles 1000, fijos en su propio
                         CSS) y el contenedor del mapa no armaba su propio
                         contexto de apilamiento, así que esos números
                         competían directo contra el z-10 de esta tarjeta y
                         le ganaban — ver el arreglo de fondo en
                         Components/FleetMap.vue (`isolate`). Ya solucionado
                         eso, el solape puede ser el de verdad (como el
                         bosquejo), no el parche de 12px. -->
                    <!-- Rediseño puramente visual (pedido explícito del usuario:
                         "mejoremos esto más profesional"): tema claro para
                         combinar con el mapa minimalista de acá arriba — antes
                         era una tarjeta oscura flotando sobre un mapa claro,
                         un contraste que se veía descuidado, no premium. -->
                    <div class="-mt-24 mx-3 sm:mx-6 lg:mx-4 relative z-10 p-4 bg-white/95 backdrop-blur-md shadow-[0_10px_35px_rgba(15,23,42,0.12)] rounded-[22px] border border-black/[0.04]">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <!-- Mini recorrido: origen y destino se leen como
                                     una secuencia conectada, no como dos textos
                                     independientes. La línea queda detrás de los
                                     nodos y termina exactamente en el destino. -->
                                <div class="relative">
                                    <span class="absolute left-[4px] top-2 bottom-2 w-px bg-gradient-to-b from-arka-primary via-arka-primary/45 to-arka-danger/70"></span>
                                    <div class="relative flex min-w-0 gap-3">
                                        <span class="relative mt-1 h-2.5 w-2.5 shrink-0 rounded-full border-2 border-white bg-arka-primary shadow-[0_0_0_2px_rgba(52,211,153,0.18)]"></span>
                                        <div class="min-w-0 flex-1">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-arka-primary">Recoger en</p>
                                        <p class="mt-0.5 truncate text-sm font-semibold text-arka-base">{{ originAddress || 'Mi ubicación' }}</p>
                                        </div>
                                    </div>

                                    <!-- Paradas adicionales (pedido explícito del usuario) —
                                         mismos nodos conectados, entre origen y destino. -->
                                    <div v-for="(stop, index) in stops" :key="index" class="relative mt-3 flex min-w-0 gap-3">
                                        <span class="relative mt-1 h-2.5 w-2.5 shrink-0 rounded-full border-2 border-white bg-amber-500 shadow-[0_0_0_2px_rgba(245,158,11,0.18)]"></span>
                                        <div class="min-w-0 flex-1">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-amber-600">Parada {{ index + 1 }}</p>
                                        <p class="mt-0.5 truncate text-sm font-semibold text-arka-base">{{ stop.address || 'Parada marcada en el mapa' }}</p>
                                        </div>
                                    </div>

                                    <div class="relative mt-3 flex min-w-0 gap-3">
                                        <span class="relative mt-1 h-2.5 w-2.5 shrink-0 rotate-45 rounded-[2px] border-2 border-white bg-arka-danger shadow-[0_0_0_2px_rgba(248,113,113,0.16)]"></span>
                                        <div class="min-w-0 flex-1">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rose-500">Destino</p>
                                        <p class="mt-0.5 truncate text-sm font-semibold text-arka-base">{{ destinationAddress || 'Destino marcado en el mapa' }}</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Pedido explícito del usuario: "indicar los km y
                                     minutos de ese recorrido" — distancia y duración
                                     REALES de manejo (OSRM, misma ruta dibujada en el
                                     mapa), no la línea recta. Bug real reportado por
                                     el usuario ("parece que calcula el km de la
                                     primera parada al destino"): acá se muestra el
                                     recorrido COMPLETO (origen→paradas→destino,
                                     totalTripDistanceKm) — el precio sigue
                                     calculándose tramo por tramo tal cual, esto es
                                     solo para que el número que lee el cliente
                                     represente el viaje entero, no un pedazo. -->
                                <p v-if="routeDistanceKm != null" class="ml-[26px] mt-2 flex items-center gap-1.5 text-xs text-arka-base/50">
                                    <span class="inline-block h-1 w-1 rounded-full bg-arka-primary"></span>
                                    {{ totalTripDistanceKm.toFixed(1) }} km · {{ Math.round(totalTripDurationMin) }} min estimados
                                </p>
                                <p v-else-if="originLat != null && destinationLat != null" class="ml-[26px] mt-2 text-xs text-arka-base/50">
                                    Calculando recorrido…
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <!-- Pedido explícito del usuario: invertir recoger/destino
                                     con un ícono, sin tener que volver a escribir las dos
                                     direcciones de nuevo. -->
                                <button
                                    type="button"
                                    class="grid h-8 w-8 place-items-center rounded-full border border-black/[0.06] text-arka-base/50 transition hover:bg-[#F5F7F6] hover:text-arka-base"
                                    aria-label="Invertir recoger y destino"
                                    title="Invertir recoger y destino"
                                    :disabled="originLat == null || destinationLat == null"
                                    @click="swapOriginDestination"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h11l-3-3M17 17H6l3 3" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-full border border-black/[0.06] px-3.5 py-2 text-xs font-semibold text-arka-base/70 transition hover:bg-[#F5F7F6] hover:text-arka-base"
                                    @click="backToDestinationStep"
                                >
                                    Cambiar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Paso 2, "Elige tu conductor" (secciones 6-10 del documento).
                     Pasajeros/cajuela/pago se movieron al cajón "Opciones del
                     viaje" del paso 3 (sección 18-19 del documento: no son
                     obligatorios para pedir, casi siempre valen lo mismo) —
                     siguen filtrando la lista de acá con sus valores por
                     defecto (1 pasajero, sin cajuela), ver driversWithDistance. -->
                <template v-if="step === 'driver'">
                <!-- Elegir conductor (secciones 6-10 y 29 del documento de
                     rediseño): 4 categorías en jerarquía fija — nunca 4
                     opciones equivalentes, Flota siempre primero. Tema claro
                     (pedido explícito del usuario: "manejemos el mismo
                     color" que Inicio) — filas blancas sobre fondo gris,
                     igual criterio que HomeSearchSheet.vue. -->
                <div class="p-4 sm:p-6 bg-gray-100 shadow rounded-arka space-y-3">
                    <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                        <h3 class="text-lg font-medium text-arka-base">Elige tu conductor</h3>

                        <!-- Pedido explícito del usuario: que el botón de pedir esté
                             también acá arriba — con listas largas se perdía de vista
                             allá abajo. Mismo botón, misma acción; el de más abajo
                             (después del precio) sigue estando para quien prefiere
                             revisar todo antes. -->
                        <PrimaryButton :disabled="!canSubmit || form.processing" @click="submit">
                            {{ whenMode === 'scheduled' ? 'Programar' : 'Pedir ahora' }}
                        </PrimaryButton>
                    </div>

                    <InputError :message="form.errors.cooperative_id" />
                    <p v-if="form.errors.driver_user_id" class="text-sm text-arka-danger">
                        {{ form.errors.driver_user_id }}
                        <span v-if="driverErrorFallbackLabel">Mostrando {{ driverErrorFallbackLabel }} en su lugar.</span>
                    </p>

                    <!-- Tarjetas de categoría (sección 8): tocarla despliega o
                         cierra su lista — "Ver disponibles" del documento — nunca
                         dispara la solicitud (sección 10). -->
                    <div class="space-y-2">
                        <button
                            v-for="category in CATEGORY_ORDER"
                            :key="category"
                            type="button"
                            class="w-full flex items-center gap-3 p-3 rounded-arka border transition text-start"
                            :class="activeCategory === category ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-base/10 bg-white hover:border-arka-primary/40'"
                            @click="selectCategory(category)"
                        >
                            <!-- Ícono por categoría (pedido explícito del usuario, con el
                                 bosquejo como referencia). -->
                            <span class="h-9 w-9 rounded-full flex items-center justify-center shrink-0" :class="CATEGORY_META[category].badgeClass">
                                <svg v-if="category === 'fleet'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="9" cy="9" r="3" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 19a5.5 5.5 0 0 1 11 0" />
                                    <circle cx="17" cy="9" r="2.4" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 13.5c2.4 0 4.5 1.9 5 5" />
                                </svg>
                                <svg v-else-if="category === 'cooperative'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                                </svg>
                                <svg v-else-if="category === 'public'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 20a7 7 0 0 1 14 0" />
                                </svg>
                                <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="8.5" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 12h17M12 3.5c2.5 2.3 3.8 5.3 3.8 8.5s-1.3 6.2-3.8 8.5c-2.5-2.3-3.8-5.3-3.8-8.5S9.5 5.8 12 3.5Z" />
                                </svg>
                            </span>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-arka-base" :class="category === 'fleet' ? 'font-semibold' : 'font-medium'">
                                        {{ CATEGORY_META[category].label }}
                                        <span
                                            v-if="category === 'fleet' && categoryCounts.fleet"
                                            class="ml-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-arka-primary/15 text-arka-primary"
                                        >
                                            Recomendado
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-right">
                                        <span class="block text-sm text-arka-base/50">{{ categoryCounts[category] }} ›</span>
                                        <span
                                            v-if="formattedStartingPrice(category)"
                                            class="mt-0.5 flex items-center justify-end gap-1 text-[11px] font-semibold text-arka-primary"
                                        >
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm.9 15.8v1.1h-1.8v-1.05c-1.55-.2-2.7-1.05-3.2-2.3l1.65-.7c.4.9 1.2 1.45 2.35 1.45 1.05 0 1.75-.45 1.75-1.15 0-.65-.55-1-2.05-1.4-2.2-.55-3.3-1.45-3.3-3.1 0-1.5 1.1-2.65 2.8-2.95V6.6h1.8v1.05c1.25.2 2.2.85 2.8 1.9l-1.55.85c-.4-.7-1.1-1.15-2.05-1.15-1.05 0-1.7.45-1.7 1.15 0 .6.55.95 2.05 1.35 2.25.6 3.3 1.5 3.3 3.15 0 1.5-1.1 2.6-2.85 2.9Z" />
                                            </svg>
                                            Desde {{ formattedStartingPrice(category) }}
                                        </span>
                                    </span>
                                </div>
                                <p class="text-xs text-arka-base/50">{{ CATEGORY_META[category].hint }}</p>
                            </div>
                        </button>
                    </div>

                    <!-- Cooperativas: sin lista de conductores propia — el backend
                         recién arma candidatos al despachar de verdad
                         (RideDispatchCandidates::forCooperative()). Elegir la
                         cooperativa ES la selección, igual que ya hacía el
                         <select> de antes. -->
                    <div v-if="activeCategory === 'cooperative'" class="space-y-2 pt-1">
                        <div class="relative">
                            <input v-model="cooperativeSearch" type="search" placeholder="Buscar cooperativa por nombre" class="w-full rounded-arka border-arka-base/10 bg-white px-4 py-3 text-sm text-arka-base focus:border-arka-primary focus:ring-arka-primary" />
                        </div>
                        <p v-if="!cooperatives.length" class="text-sm text-arka-base/50 py-2">
                            Todavía no tiene cooperativas en su red.
                        </p>
                        <div
                            v-for="cooperative in filteredCooperatives"
                            :key="cooperative.id"
                            class="flex items-center justify-between gap-3 p-3 rounded-arka border cursor-pointer"
                            :class="selectedCooperativeId === cooperative.id ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-base/10 bg-white'"
                            role="radio"
                            :aria-checked="selectedCooperativeId === cooperative.id"
                            tabindex="0"
                            @click="selectedCooperativeId = cooperative.id; step = 'confirm'"
                            @keydown.enter.prevent="selectedCooperativeId = cooperative.id; step = 'confirm'"
                            @keydown.space.prevent="selectedCooperativeId = cooperative.id; step = 'confirm'"
                        >
                            <span class="flex min-w-0 items-center gap-3">
                                <input type="radio" :value="cooperative.id" v-model="selectedCooperativeId" class="text-arka-primary" tabindex="-1" aria-hidden="true" />
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border border-arka-base/10 bg-arka-primary/10">
                                    <img
                                        v-if="cooperative.logo_url"
                                        :src="cooperative.logo_url"
                                        :alt="`Logo de ${cooperative.name}`"
                                        class="h-full w-full object-cover"
                                    />
                                    <span v-else class="text-sm font-bold text-arka-primary">
                                        {{ cooperative.name?.trim().charAt(0).toUpperCase() || 'C' }}
                                    </span>
                                </span>
                                <span class="min-w-0">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span class="truncate font-medium text-arka-base">{{ cooperative.name }}</span>
                                        <span
                                            v-if="recommendedCooperative?.id === cooperative.id"
                                            class="shrink-0 rounded-full bg-arka-primary/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-arka-primary"
                                        >
                                            Recomendada
                                        </span>
                                    </span>
                                    <!-- Bug reportado por el usuario ("ese enlace no funciona"):
                                         el componente <Link> de Inertia ignora `target` y siempre
                                         intercepta el click con su propio router SPA — con eso,
                                         en vez de abrir una pestaña nueva, sacaba al cliente del
                                         formulario de pedir carrera en la misma pestaña. Un <a>
                                         nativo sí respeta target="_blank" de verdad. -->
                                    <a
                                        :href="route('cooperatives.show', cooperative.public_id)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-arka-primary hover:underline"
                                        aria-label="Ver el perfil público de la cooperativa en otra pestaña"
                                        @click.stop
                                        @keydown.stop
                                    >
                                        Ver perfil público <span aria-hidden="true">↗</span>
                                    </a>
                                </span>
                            </span>
                            <span class="shrink-0 text-right text-sm text-arka-base/50">{{ cooperative.active_driver_memberships_count }} unidades<br><small v-if="cooperative.distance_km != null">{{ cooperative.distance_km }} km · ~{{ Math.max(1, Math.ceil(cooperative.distance_km / 0.45)) }} min desde el origen</small></span>
                        </div>
                        <p v-if="cooperatives.length" class="text-xs text-arka-base/50">
                            Dejamos seleccionada la opción recomendada por precio estimado y cercanía. Puede cambiarla antes de pedir.
                        </p>
                    </div>

                    <!-- Flota / Públicos / Todos: lista compacta de conductores de
                         esa categoría (sección 33: quién es, si es de confianza,
                         cuánto tarda, cuánto cuesta, qué vehículo tiene). -->
                    <div v-else-if="activeCategory" class="space-y-3 pt-1">
                        <p v-if="!driversWithDistance.length" class="text-sm text-arka-base/50 py-2">
                            0 disponibles ahora en esta categoría para {{ passengerCount }} pasajero(s){{ needsTrunk ? ' con cajuela' : '' }}.
                            <button
                                v-if="nextNonEmptyCategory(activeCategory)"
                                type="button"
                                class="underline hover:text-arka-base"
                                @click="activeCategory = nextNonEmptyCategory(activeCategory)"
                            >
                                Probar con {{ CATEGORY_META[nextNonEmptyCategory(activeCategory)].label.toLowerCase() }}.
                            </button>
                        </p>

                        <template v-else>
                            <label
                                class="flex items-center gap-3 p-3 rounded-arka border cursor-pointer"
                                :class="selectedDriverId === WHOLE_FLEET ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-base/10 bg-white'"
                            >
                                <input type="radio" :value="WHOLE_FLEET" v-model="selectedDriverId" class="text-arka-primary" @change="step = 'confirm'" />
                                <span class="text-arka-base font-medium">{{ WHOLE_POOL_LABEL[sourceMode] }}</span>
                            </label>

                            <!-- Pedido explícito del usuario: no había ningún orden claro
                                 con flotas grandes — el disponible siempre va primero,
                                 esto solo cambia el desempate entre ellos. -->
                            <div class="flex items-center justify-between gap-3 rounded-arka border border-arka-base/10 bg-white p-2.5">
                                <span class="flex shrink-0 items-center gap-1.5 text-xs font-medium text-arka-base/55">
                                    <svg class="h-4 w-4 text-arka-primary" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M3 5.25A1.25 1.25 0 0 1 4.25 4h15.5a1.25 1.25 0 1 1 0 2.5H4.25A1.25 1.25 0 0 1 3 5.25ZM6 12a1.25 1.25 0 0 1 1.25-1.25h9.5a1.25 1.25 0 1 1 0 2.5h-9.5A1.25 1.25 0 0 1 6 12Zm4.25 6.75a1.25 1.25 0 1 1 0-2.5h3.5a1.25 1.25 0 1 1 0 2.5h-3.5Z" />
                                    </svg>
                                    Ordenar
                                </span>
                                <SearchableSelect
                                    id="driver_sort"
                                    v-model="sortBy"
                                    :options="SORT_OPTION_LIST"
                                    light
                                    class="min-w-0 w-44 max-w-[60%]"
                                />
                            </div>

                            <!-- Foto + nombre + ⭐ + precio estimado por fila (rediseño UX, con
                                 mockup de referencia estilo Uber/DiDi) — antes solo había un
                                 punto de color aislado, sin foto ni precio propio. El punto de
                                 estado se mantiene, ahora como insignia sobre la esquina del
                                 avatar, y también en texto (STATUS_STYLE[...].label) para no
                                 depender solo del color. -->
                            <label
                                v-for="driver in pagedDrivers"
                                :key="`${driver.source}-${driver.user_id}`"
                                class="flex items-center justify-between gap-3 p-3 rounded-arka border cursor-pointer"
                                :class="[
                                    selectedDriverId === driver.user_id ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-base/10 bg-white',
                                    STATUS_STYLE[driver.status].textClass,
                                    driver.outOfRange ? 'opacity-50 grayscale' : '',
                                ]"
                            >
                                <input
                                    type="radio"
                                    :value="driver.user_id"
                                    v-model="selectedDriverId"
                                    :disabled="driver.status === 'offline' || driver.outOfRange"
                                    class="text-arka-primary shrink-0"
                                    @change="step = 'confirm'"
                                />
                                <span class="relative shrink-0">
                                    <!-- `role: 'conductor'` a mano: driverCardData() manda un
                                         array armado a propósito, sin esa clave — así UserAvatar
                                         cae en el ícono de volante (no el de persona) cuando no
                                         hay foto, coherente con quién es. -->
                                    <UserAvatar :user="{ ...driver, role: 'conductor' }" size-class="h-11 w-11 text-sm" />
                                    <span
                                        class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full ring-2 ring-white"
                                        :class="STATUS_STYLE[driver.status].dot"
                                        :title="STATUS_STYLE[driver.status].label"
                                    />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-arka-base font-medium">{{ driver.name }}</span>
                                        <span
                                            v-if="driver.source === 'public'"
                                            class="px-1.5 py-0.5 rounded text-[10px] font-medium"
                                            :class="tierColorClass(driver.tier.color_key)"
                                        >
                                            {{ tierLabel(driver.tier) }}
                                        </span>
                                        <span v-if="driver.review_count > 0" class="text-xs text-arka-base/60">
                                            ★ {{ Number(driver.average_rating).toFixed(1) }}
                                        </span>
                                    </span>
                                    <span class="block text-xs text-arka-base/50">
                                        {{ driver.outOfRange ? 'Fuera de su zona de cobertura' : STATUS_STYLE[driver.status].label }}
                                    </span>
                                    <DriverCategoryBadge class="mt-1" :label="driver.public_category_label" />
                                    <!-- Pedido explícito del usuario: qué vehículo tiene, para
                                         saber qué esperar antes de pedirle la carrera. Placa
                                         tapada, no completa (confidencialidad, ver
                                         DriverProfile::maskedPlate()). -->
                                    <span v-if="driver.vehicle_make" class="block text-xs text-arka-base/40 truncate">
                                        {{ driver.vehicle_make }} {{ driver.vehicle_model }} {{ driver.vehicle_color }}
                                        <span v-if="driver.vehicle_type"> · {{ driver.vehicle_type }}</span>
                                        · {{ driver.vehicle_plate }}
                                        · {{ driver.passenger_capacity }} pasajero(s)
                                        <span v-if="driver.has_trunk"> · con cajuela</span>
                                    </span>
                                </span>
                                <!-- Precio estimado (nuevo, con mockup de referencia: cada fila
                                     muestra su propio precio, no solo el km/rate) y, debajo, el
                                     ETA en minutos — pedido explícito del usuario ("manejar la
                                     privacidad... los km cercano manejemos minutos mejor para la
                                     distancia"): nunca km exacto hasta un conductor puntual. -->
                                <span class="text-right shrink-0">
                                    <span v-if="estimatedTotalPriceForDriver(driver) != null" class="block text-sm font-semibold text-arka-base">
                                        ${{ estimatedTotalPriceForDriver(driver).toFixed(2) }}
                                    </span>
                                    <span v-if="driver.etaMinutes != null" class="block text-xs text-arka-base/50">{{ driver.etaMinutes }} min</span>
                                </span>
                            </label>

                            <!-- Paginado (pedido explícito del usuario): de a 5, para no tener
                                 que scrollear una lista de 20+ conductores. -->
                            <div v-if="totalDriverPages > 1" class="flex items-center justify-between gap-2 text-sm">
                                <SecondaryButton :disabled="currentPage === 1" @click="currentPage--">Anterior</SecondaryButton>
                                <span class="text-arka-base/50">Página {{ currentPage }} de {{ totalDriverPages }}</span>
                                <SecondaryButton :disabled="currentPage === totalDriverPages" @click="currentPage++">Siguiente</SecondaryButton>
                            </div>
                        </template>
                    </div>

                    <p v-if="!categoryCounts.fleet && !categoryCounts.cooperative && !categoryCounts.public" class="text-sm text-arka-base/50 py-2">
                        Todavía no tiene conductores acá.
                        <a :href="route('fleet.index')" class="text-arka-primary hover:text-arka-primary-bright">Vaya a Mi Flota para invitar a alguno</a>
                        o mire el
                        <a :href="route('directory.index')" class="text-arka-primary hover:text-arka-primary-bright">directorio público</a>
                        (sección 3.4: la red de respaldo cuando nadie de su flota está disponible).
                    </p>
                </div>

                </template>

                <!-- Paso 3, "Confirma y pide" (sección 11 del documento):
                     resumen de todo lo elegido, de un vistazo, con "Pedir
                     ahora" como única acción principal — sin una segunda
                     pantalla preguntando "¿está seguro?" (regla explícita
                     del documento). -->
                <template v-if="step === 'confirm'">
                <!-- Tema claro (pedido explícito del usuario: "manejemos el
                     mismo color" que la pantalla de elegir conductor). -->
                <div class="p-4 sm:p-6 bg-gray-100 shadow rounded-arka space-y-3">
                    <!-- Foto grande + píldora de ETA (rediseño UX, con mockup de
                         referencia): antes era un punto de color + texto plano. -->
                    <div v-if="selectedDriverInfo" class="flex items-center gap-3">
                        <span class="relative shrink-0">
                            <UserAvatar :user="{ ...selectedDriverInfo, role: 'conductor' }" size-class="h-14 w-14 text-base" />
                            <span
                                class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full ring-2 ring-gray-100"
                                :class="STATUS_STYLE[selectedDriverInfo.status].dot"
                                :title="STATUS_STYLE[selectedDriverInfo.status].label"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-arka-base font-medium flex items-center gap-1.5 flex-wrap">
                                {{ selectedDriverInfo.name }}
                                <span
                                    v-if="selectedDriverInfo.source === 'public'"
                                    class="px-1.5 py-0.5 rounded text-[10px] font-medium"
                                    :class="tierColorClass(selectedDriverInfo.tier.color_key)"
                                >
                                    {{ tierLabel(selectedDriverInfo.tier) }}
                                </span>
                                <span v-if="selectedDriverInfo.review_count > 0" class="text-xs text-arka-base/60">
                                    ★ {{ Number(selectedDriverInfo.average_rating).toFixed(1) }}
                                </span>
                            </p>
                            <p v-if="selectedDriverInfo.vehicle_make" class="text-sm text-arka-base/50 truncate">
                                {{ selectedDriverInfo.vehicle_make }} {{ selectedDriverInfo.vehicle_model }} · {{ selectedDriverInfo.vehicle_plate }}
                            </p>
                            <DriverCategoryBadge class="mt-1" :label="selectedDriverInfo.public_category_label" />
                        </div>
                        <!-- Píldora de ETA (mismo dato que antes, "Llegada Xmin" en texto
                             plano — ahora con el mismo lenguaje visual que el resto del
                             rediseño). -->
                        <span
                            v-if="selectedDriverInfo.etaMinutes != null"
                            class="shrink-0 px-3 py-1.5 rounded-full bg-arka-primary/15 text-arka-primary text-xs font-semibold"
                        >
                            Llegando en {{ selectedDriverInfo.etaMinutes }} min
                        </span>
                    </div>
                    <div v-else-if="selectedCooperativeInfo">
                        <p class="text-arka-base font-medium">{{ selectedCooperativeInfo.name }}</p>
                        <p class="text-sm text-arka-base/50">Cooperativa · asigna una unidad verificada</p>
                    </div>
                    <div v-else>
                        <p class="text-arka-base font-medium">{{ WHOLE_POOL_LABEL[sourceMode] }}</p>
                        <p class="text-sm text-arka-base/50">Se ofrece primero al más cercano</p>
                    </div>

                    <p v-if="!selectedDriverStillFits" class="text-xs text-arka-danger">
                        Este conductor no tiene lugar para {{ passengerCount }} pasajero(s){{ needsTrunk ? ' con cajuela' : '' }} —
                        vuelva a "Elegir conductor" o baje la cantidad en Opciones del viaje.
                    </p>

                    <!-- Precio (sección 5): estimado a partir de la distancia y la
                         tarifa, desglosado y editable — el cliente puede aceptarlo o
                         contraofertar. -->
                    <div v-if="estimatedPrice != null" class="pt-2 border-t border-arka-base/10 space-y-2">
                        <!-- Desglose por parada (pedido explícito del usuario: "cada
                             parada se calcula diferente e individual") — cada tramo
                             con su propio precio, antes del tramo final. -->
                        <div v-for="(stop, index) in stopsWithPrices" :key="index" class="flex items-center justify-between text-sm text-arka-base/50">
                            <span>Parada {{ index + 1 }}{{ stop.distanceKm != null ? ` · ${stop.distanceKm.toFixed(1)} km` : '' }}</span>
                            <span class="text-arka-base font-medium">{{ stop.price != null ? `$${stop.price.toFixed(2)}` : 'Calculando…' }}</span>
                        </div>

                        <div class="flex items-center justify-between text-sm text-arka-base/50">
                            <!-- Si el mínimo configurado ya supera lo que daría distancia ×
                                 tarifa, mostrar ese cálculo sería engañoso — no es lo que se
                                 termina cobrando (fix reportado por el usuario). -->
                            <span v-if="isMinimumFareApplied">Tarifa mínima de la plataforma</span>
                            <span v-else>{{ realDistanceKm.toFixed(1) }} km × ${{ referenceRatePerKm.toFixed(2) }}/km{{ stops.length ? ' (tramo final)' : '' }}</span>
                            <span class="text-arka-base font-medium">${{ estimatedPrice.toFixed(2) }} (estimado)</span>
                        </div>

                        <div v-if="stopsTotalPrice != null" class="flex items-center justify-between text-sm font-semibold pt-1 border-t border-arka-base/10">
                            <span class="text-arka-base">Total del recorrido</span>
                            <span class="text-arka-primary-bright">${{ estimatedTotalPrice.toFixed(2) }}</span>
                        </div>

                        <label class="flex items-center gap-2">
                            <input type="checkbox" v-model="useCustomPrice" class="text-arka-primary rounded" />
                            <span class="text-sm text-arka-base">Proponer otro monto en vez del estimado</span>
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

                        <!-- Cargo por trayecto de recogida (pedido explícito del
                             usuario): aviso sutil, sin monto ni porcentaje — deja
                             claro que el total de ACÁ ARRIBA ya lo incluye, no que
                             "podría" aplicarse. Solo aparece cuando el conductor
                             elegido tiene la función activada Y está lo bastante
                             lejos como para que el cargo sea mayor a $0 (ver
                             showsPickupSurchargeNotice). El conductor sigue
                             pudiendo decidir no cobrarlo al recibir la solicitud —
                             esto es un estimado, no una promesa de cobro. -->
                        <p v-if="showsPickupSurchargeNotice" class="text-xs italic text-emerald-600 dark:text-emerald-400">
                            🍃 Tu conductor viene desde más lejos. El total incluye un aporte por su desplazamiento.
                        </p>
                    </div>

                    <!-- Fila de pago tocable (sección 18: "no convertir la
                         elección de pago en una pantalla obligatoria") — abre el
                         mismo cajón de opciones, ya en el campo de pago. -->
                    <button
                        type="button"
                        class="w-full flex items-center justify-between pt-2 border-t border-arka-base/10 text-start"
                        @click="showRideOptions = true"
                    >
                        <span class="text-sm text-arka-base/50">Forma de pago</span>
                        <span class="text-sm text-arka-base">{{ paymentMethod === 'efectivo' ? 'Efectivo' : 'Transferencia' }} ›</span>
                    </button>

                    <!-- Acceso discreto (sección 19 del documento): el usuario
                         normal nunca necesita entrar acá. -->
                    <button
                        type="button"
                        class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        @click="showRideOptions = true"
                    >
                        Opciones del viaje ({{ rideOptionsSummary }}) ›
                    </button>
                </div>

                <!-- Bug real reportado por el usuario: sin este aviso, el botón
                     "Pedir ahora" deshabilitado por una parada sin coordenadas
                     no daba ninguna pista de por qué no reaccionaba. -->
                <p v-if="hasUnresolvedStop" class="text-center text-xs text-arka-warning">
                    Completá todas las paradas (elegí una sugerencia de la lista) antes de pedir.
                </p>

                <!-- Sticky (sección 34: "Pedir ahora" siempre accesible con el
                     pulgar) — sin segunda pantalla de "¿está seguro?" (sección 11). -->
                <div class="sticky bottom-4 pt-2">
                    <PrimaryButton class="w-full justify-center shadow-lg" :disabled="!canSubmit || form.processing" @click="submit">
                        {{ whenMode === 'scheduled' ? 'Programar carrera' : 'Pedir ahora' }}
                    </PrimaryButton>
                </div>
                </template>

                <!-- Cajón "Opciones del viaje" (secciones 18-19 del documento):
                     pasajeros, cajuela y forma de pago — nada de esto bloquea el
                     camino principal, valores por defecto para casi todos. -->
                <BottomSheet :show="showRideOptions" @close="showRideOptions = false">
                    <div class="p-4 sm:p-6 space-y-4">
                        <h3 class="text-lg font-medium text-arka-text">Opciones del viaje</h3>

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

                        <PrimaryButton class="w-full justify-center" @click="showRideOptions = false">Listo</PrimaryButton>
                    </div>
                </BottomSheet>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
