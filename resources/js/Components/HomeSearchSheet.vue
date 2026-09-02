<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AddressAutocomplete from '@/Components/AddressAutocomplete.vue';
import BottomSheet from '@/Components/BottomSheet.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';

// Contenido del "bottom sheet" de Inicio (buscador + accesos rápidos +
// recientes + programar viaje) — extraído a su propio componente porque
// Dashboard.vue lo necesita en DOS layouts totalmente distintos (móvil: capa
// fija a pantalla completa; escritorio: tarjeta normal en el flujo del
// documento, pedido explícito del usuario: "en web tiene que ser un poco
// diferente"). Repetir este bloque de marcado dos veces a mano ya había
// generado divergencias reales entre uno y otro — mejor un único lugar.
const props = defineProps({
    modelValue: { type: String, default: '' },
    frequentPlaces: { type: Array, default: () => [] },
    canSchedule: { type: Boolean, default: false },
    scheduleHref: { type: String, default: null },
    // Direcciones guardadas por el cliente (pedido explícito del usuario:
    // "agregas un + en esa barra para que guarde direcciones... y esas que
    // son mas usadas utiliza los iconos") — reemplazan los 3 chips fijos de
    // Casa/Trabajo/Aeropuerto de antes por chips reales que el cliente arma.
    savedRoutes: { type: Array, default: () => [] },
    // Ubicación en vivo del cliente (Dashboard.vue ya la resuelve al montar,
    // ver requestGeolocation()) — se manda como origen al guardar una
    // dirección porque el backend lo exige (SavedRouteController::store()),
    // aunque acá no se use para nada más que eso.
    originLat: { type: Number, default: null },
    originLng: { type: Number, default: null },
    // Prioriza calles y avenidas cercanas a la ubicación real del cliente.
    // Es una preferencia, no un límite: todavía puede buscar en todo Ecuador.
    searchCenter: { type: Object, default: null },
    // Variante móvil compacta: el mapa conserva el protagonismo, Programar
    // se integra con los accesos rápidos y solo Recientes tiene scroll.
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'place-selected', 'select-recent', 'destination-loading']);

// Card compacta de Inicio (pedido explícito del usuario: "no quiero una
// sección grande... la card inicial debe contener únicamente título,
// subtítulo, buscador, Agregar y Programar") — Recientes y las direcciones
// guardadas no desaparecen ni pierden datos, solo se difiere cuándo se
// pintan: recién al tocar el buscador. En la variante de escritorio
// (`compact` false) esto no aplica, sigue todo visible como antes.
const recentsExpanded = ref(false);

// Bug real reportado por el usuario ("no se ve nada bien", con captura): la
// lista de "Recientes" mostraba la dirección completa pegada en una sola
// línea larga y cortada. `FrequentPlaces` solo guarda un string de dirección
// completo (ver App\Services\FrequentPlaces), sin nombre y detalle por
// separado — se separa acá por la primera coma, que es como Google Places
// arma sus resultados ("Nombre del lugar, resto de la dirección").
function splitAddress(address) {
    const [title, ...rest] = address.split(',');
    return { title: title.trim(), subtitle: rest.join(',').trim() };
}

// Solo estas 3 categorías tienen ícono propio (pedido explícito del usuario,
// con las imágenes que dejó en public/img/) — cualquier otro alias ("Otra",
// texto libre) cae al pin genérico de abajo.
const CATEGORY_ICONS = {
    casa: '/img/casa.png',
    trabajo: '/img/portafolio.png',
    aeropuerto: '/img/avion.png',
};
const CATEGORY_LABELS = { casa: 'Casa', trabajo: 'Trabajo', aeropuerto: 'Aeropuerto' };

function normalizeAlias(text) {
    return (text ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

function iconForAlias(alias) {
    const normalized = normalizeAlias(alias);
    const match = Object.keys(CATEGORY_ICONS).find((key) => normalized.includes(key));
    return match ? CATEGORY_ICONS[match] : null;
}

// Solo las que ya tienen destino guardado sirven como acceso directo — una
// SavedRoute puede existir sin haberse usado nunca para pedir carrera todavía.
const savedChips = computed(() => props.savedRoutes.filter((r) => r.destination_lat != null && r.destination_lng != null));

function selectSavedRoute(savedRoute) {
    emit('select-recent', {
        lat: Number(savedRoute.destination_lat),
        lng: Number(savedRoute.destination_lng),
        address: savedRoute.destination_address,
    });
}

// Formulario del "+" (pedido explícito del usuario): un desplegable con las
// categorías más usadas y una opción "Otra" de texto libre.
const showAddForm = ref(false);
const category = ref('casa');
const customAlias = ref('');
const destinationQuery = ref('');
const destinationPlace = ref(null);

const form = useForm({
    alias: '',
    origin_lat: null,
    origin_lng: null,
    origin_address: 'Mi ubicación',
    destination_lat: null,
    destination_lng: null,
    destination_address: '',
});

function openAddForm() {
    category.value = 'casa';
    customAlias.value = '';
    destinationQuery.value = '';
    destinationPlace.value = null;
    form.clearErrors();
    showAddForm.value = true;
}

function closeAddForm() {
    showAddForm.value = false;
}

function onDestinationSelected(place) {
    destinationPlace.value = place;
    destinationQuery.value = place.address;
}

const canSave = computed(
    () =>
        destinationPlace.value != null &&
        props.originLat != null &&
        props.originLng != null &&
        (category.value !== 'otra' || customAlias.value.trim().length > 0)
);

function saveAddress() {
    if (!canSave.value) return;

    form.alias = category.value === 'otra' ? customAlias.value.trim() : CATEGORY_LABELS[category.value];
    form.origin_lat = props.originLat;
    form.origin_lng = props.originLng;
    form.destination_lat = destinationPlace.value.lat;
    form.destination_lng = destinationPlace.value.lng;
    form.destination_address = destinationPlace.value.address;

    form.post(route('saved-routes.store'), {
        preserveScroll: true,
        onSuccess: () => closeAddForm(),
    });
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col" :class="compact ? 'gap-3' : 'gap-5'">
    <div>
        <h2 :class="compact ? 'text-xl leading-tight font-bold mb-0.5' : 'text-2xl font-bold mb-0'" class="tracking-tight text-arka-base">¿A dónde vamos?</h2>
        <!-- Pedido explícito del usuario: reducir a un detalle discreto, no
             una segunda línea que compita con el título. -->
        <p :class="compact ? 'text-xs text-[#929B97]' : 'text-[11px] text-arka-base/35'" class="leading-relaxed">Tu ubicación actual será el punto de partida.</p>
        <div :class="compact ? 'mt-2.5' : 'mt-3'">
            <AddressAutocomplete
                :model-value="modelValue"
                :favorites="frequentPlaces"
                :city-bias="searchCenter"
                placeholder="Buscar destino"
                flat
                @update:model-value="$emit('update:modelValue', $event)"
                @place-selected="$emit('place-selected', $event)"
                @selection-loading="$emit('destination-loading', $event)"
                @focus="recentsExpanded = true"
            />
            <p class="mt-1.5 px-1 text-[10px] leading-relaxed text-arka-base/40">
                Consejo: escribe la avenida o calle principal y la transversal. Después podrás ajustar el punto arrastrándolo en el mapa.
            </p>
        </div>
    </div>

    <!-- Card inicial compacta (pedido explícito del usuario): en móvil,
         Agregar/Programar quedan siempre visibles pero chicos — Recientes y
         las direcciones guardadas se arman más abajo, iguales, solo que
         recién aparecen al tocar el buscador. -->
    <div v-if="compact" class="flex items-center justify-between">
        <button
            type="button"
            class="flex items-center gap-1 px-0.5 text-xs font-medium text-arka-base/45 transition hover:text-arka-primary"
            @click="openAddForm"
        >
            <span class="text-sm leading-none">＋</span>
            Agregar
        </button>

        <Link
            v-if="canSchedule"
            :href="scheduleHref"
            class="flex h-9 items-center gap-1.5 rounded-[20px] border border-[#A8EDD2] bg-[#ECFBF5] px-3.5 text-xs font-semibold text-[#17201D] transition hover:bg-[#DFF7EC]"
        >
            <svg class="h-4 w-4 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="5" width="18" height="16" rx="2" />
                <path stroke-linecap="round" d="M8 3v4M16 3v4M3 10h18" />
            </svg>
            Programar
        </Link>
    </div>

    <template v-if="!compact || recentsExpanded">
    <!-- Direcciones guardadas + "+" para agregar una nueva (pedido explícito
         del usuario: reemplaza los 3 chips fijos de Casa/Trabajo/Aeropuerto
         de antes). Scroll horizontal porque la cantidad ya no es fija.
         Oculto en la card compacta hasta expandir (ver `recentsExpanded`
         arriba) — el botón "+ Agregar" de acá arriba ya cubre esa acción
         en ese estado, así que no se repite en esta fila. -->
    <div v-if="!compact" class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <button
            v-for="savedRoute in savedChips"
            :key="savedRoute.id"
            type="button"
            class="flex min-h-10 max-w-[10rem] flex-none items-center gap-2 rounded-full border border-arka-base/10 bg-white px-3.5 py-2 text-xs font-semibold text-arka-base shadow-sm transition hover:border-arka-primary/40 hover:shadow"
            @click="selectSavedRoute(savedRoute)"
        >
            <img v-if="iconForAlias(savedRoute.alias)" :src="iconForAlias(savedRoute.alias)" class="h-4 w-4 shrink-0" alt="" />
            <span v-else class="text-sm shrink-0">📍</span>
            <span class="truncate">{{ savedRoute.alias || 'Guardada' }}</span>
        </button>

        <!-- Pedido explícito del usuario: acción secundaria, sin tarjeta ni
             sombra — no debe competir visualmente con las direcciones
             guardadas de al lado. -->
        <button
            type="button"
            class="flex min-h-10 flex-none items-center gap-1 px-1.5 text-xs font-medium text-arka-base/45 transition hover:text-arka-primary"
            @click="openAddForm"
        >
            <span class="text-sm leading-none">＋</span>
            Agregar
        </button>
    </div>

    <!-- Chips guardados en la card compacta expandida: mismos datos y
         evento, fila propia (sin "+ Agregar" repetido ni "Programar", que
         ya viven arriba siempre visibles). -->
    <div v-if="compact && savedChips.length" class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <button
            v-for="savedRoute in savedChips"
            :key="savedRoute.id"
            type="button"
            class="flex min-h-9 max-w-[10rem] flex-none items-center gap-2 rounded-full border border-arka-base/10 bg-white px-3 py-1.5 text-xs font-semibold text-arka-base shadow-sm transition hover:border-arka-primary/40 hover:shadow"
            @click="selectSavedRoute(savedRoute)"
        >
            <img v-if="iconForAlias(savedRoute.alias)" :src="iconForAlias(savedRoute.alias)" class="h-4 w-4 shrink-0" alt="" />
            <span v-else class="text-sm shrink-0">📍</span>
            <span class="truncate">{{ savedRoute.alias || 'Guardada' }}</span>
        </button>
    </div>

    <!-- Recientes (pedido explícito del usuario: lista limpia, sin "tarjetas
         dentro de tarjetas" — filas separadas por una línea fina, no cada
         una con su propio fondo/sombra). -->
    <div
        v-if="frequentPlaces.length"
        class="flex flex-col"
        :class="compact ? 'min-h-0 flex-1 overflow-y-auto overscroll-contain pb-1 [scrollbar-width:thin]' : ''"
    >
        <div class="mb-1 flex items-center justify-between">
            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-arka-base/45">Recientes</p>
            <span class="text-[11px] text-arka-base/35">Toca para repetir</span>
        </div>
        <button
            v-for="place in frequentPlaces.slice(0, compact ? 6 : 3)"
            :key="place.address"
            type="button"
            class="group flex min-h-14 w-full items-center gap-3 border-b border-[#EEF1F0] py-3 text-start transition last:border-b-0 active:bg-[#F6F8F7]"
            @click="$emit('select-recent', place)"
        >
            <!-- Reloj/historial en vez del pin de ubicación (pedido explícito
                 del usuario): comunica "algo que ya usaste", no "un lugar
                 nuevo por marcar". -->
            <svg class="h-[18px] w-[18px] shrink-0 text-arka-base/35" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="8.5" stroke-linecap="round" stroke-linejoin="round" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4.3l2.8 1.7" />
            </svg>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-arka-base">{{ splitAddress(place.address).title }}</span>
                <span v-if="splitAddress(place.address).subtitle" class="block truncate text-[12.5px] text-[#89938F]">
                    {{ splitAddress(place.address).subtitle }}
                </span>
            </span>
            <svg class="h-4 w-4 shrink-0 text-arka-base/20 transition group-hover:translate-x-0.5 group-hover:text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
            </svg>
        </button>
    </div>
    </template>

    <!-- Programar viaje: estilo secundario a propósito — no debe competir
         con seleccionar un destino. -->
    <Link
        v-if="canSchedule && !compact"
        :href="scheduleHref"
        class="flex min-h-11 w-full items-center justify-center gap-2 rounded-full border border-arka-base/10 bg-white text-sm font-semibold text-arka-base/70 shadow-sm transition hover:border-arka-primary/30 hover:text-arka-base"
    >
        <svg class="h-4 w-4 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="5" width="18" height="16" rx="2" />
            <path stroke-linecap="round" d="M8 3v4M16 3v4M3 10h18" />
        </svg>
        Programar viaje
    </Link>

    <!-- Formulario para guardar una dirección nueva (pedido explícito del
         usuario: desplegable de categorías + "Otra" de texto libre). -->
    <BottomSheet :show="showAddForm" @close="closeAddForm">
        <div class="p-4 pb-6 space-y-4">
            <h3 class="text-lg font-medium text-arka-text">Guardar dirección</h3>

            <div>
                <InputLabel for="address_category" value="Categoría" />
                <select
                    id="address_category"
                    v-model="category"
                    class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary"
                >
                    <option value="casa">Casa</option>
                    <option value="trabajo">Trabajo</option>
                    <option value="aeropuerto">Aeropuerto</option>
                    <option value="otra">Otra</option>
                </select>
            </div>

            <div v-if="category === 'otra'">
                <InputLabel for="address_custom_alias" value="¿Cómo la llamamos?" />
                <TextInput id="address_custom_alias" v-model="customAlias" class="mt-1 block w-full" maxlength="50" placeholder="Ej. Casa de mis papás" />
            </div>

            <div>
                <InputLabel value="Dirección" />
                <div class="mt-1">
                    <AddressAutocomplete
                        :model-value="destinationQuery"
                        placeholder="Buscar dirección"
                        @update:model-value="destinationQuery = $event"
                        @place-selected="onDestinationSelected"
                        @clear="destinationPlace = null"
                    />
                </div>
            </div>

            <p v-if="originLat == null" class="text-xs text-arka-text-muted">Esperando tu ubicación para poder guardar…</p>
            <InputError :message="form.errors.destination_lat ?? form.errors.origin_lat" />

            <div class="flex gap-2">
                <SecondaryButton type="button" class="flex-1 justify-center" @click="closeAddForm">Cancelar</SecondaryButton>
                <PrimaryButton type="button" class="flex-1 justify-center" :disabled="!canSave || form.processing" @click="saveAddress">
                    Guardar
                </PrimaryButton>
            </div>
        </div>
    </BottomSheet>
    </div>
</template>
