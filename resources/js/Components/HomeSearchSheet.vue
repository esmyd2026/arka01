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
    // Variante móvil compacta: el mapa conserva el protagonismo, Programar
    // se integra con los accesos rápidos y solo Recientes tiene scroll.
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'place-selected', 'select-recent', 'destination-loading']);

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
    <div class="flex min-h-0 flex-1 flex-col" :class="compact ? 'gap-3.5' : 'gap-5'">
    <div>
        <h2 class="text-xl font-bold tracking-tight text-arka-base">¿A dónde vas?</h2>
        <p class="mt-1 text-xs leading-relaxed text-arka-base/50">Tu ubicación actual será el punto de partida.</p>
        <div class="mt-4">
            <AddressAutocomplete
                :model-value="modelValue"
                :favorites="frequentPlaces"
                placeholder="Buscar destino"
                light
                @update:model-value="$emit('update:modelValue', $event)"
                @place-selected="$emit('place-selected', $event)"
                @selection-loading="$emit('destination-loading', $event)"
            />
        </div>
    </div>

    <!-- Direcciones guardadas + "+" para agregar una nueva (pedido explícito
         del usuario: reemplaza los 3 chips fijos de Casa/Trabajo/Aeropuerto
         de antes). Scroll horizontal porque la cantidad ya no es fija. -->
    <div class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
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

        <button
            type="button"
            class="flex min-h-10 flex-none items-center gap-2 rounded-full border border-dashed border-arka-base/25 bg-transparent px-3.5 py-2 text-xs font-semibold text-arka-base/65 transition hover:border-arka-primary/50 hover:bg-white"
            @click="openAddForm"
        >
            <span class="text-sm">＋</span>
            Agregar
        </button>

        <!-- En móvil vive junto a los accesos rápidos, no al final de una
             tarjeta alta. Así sigue visible sin quitarle espacio al mapa. -->
        <Link
            v-if="compact && canSchedule"
            :href="scheduleHref"
            class="flex min-h-10 flex-none items-center gap-2 rounded-full border border-arka-primary/25 bg-arka-primary/10 px-3.5 py-2 text-xs font-semibold text-arka-base transition hover:bg-arka-primary/15"
        >
            <svg class="h-4 w-4 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="5" width="18" height="16" rx="2" />
                <path stroke-linecap="round" d="M8 3v4M16 3v4M3 10h18" />
            </svg>
            Programar
        </Link>
    </div>

    <!-- Recientes (pedido explícito del usuario, con imagen de referencia):
         cada uno como su propia tarjeta blanca redondeada, no una fila de
         lista plana — máximo 3, título+dirección en dos líneas. -->
    <div
        v-if="frequentPlaces.length"
        class="space-y-2.5"
        :class="compact ? 'min-h-0 flex-1 overflow-y-auto overscroll-contain pr-1 pb-1 [scrollbar-width:thin]' : ''"
    >
        <div class="flex items-center justify-between">
            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-arka-base/45">Recientes</p>
            <span class="text-[11px] text-arka-base/35">Toca para repetir</span>
        </div>
        <button
            v-for="place in frequentPlaces.slice(0, compact ? 6 : 3)"
            :key="place.address"
            type="button"
            class="group flex min-h-[3.75rem] w-full items-center gap-3 rounded-2xl border border-arka-base/[0.04] bg-white px-3.5 py-2.5 text-start shadow-[0_5px_18px_rgba(15,23,42,0.045)] transition hover:-translate-y-px hover:shadow-md active:translate-y-0"
            @click="$emit('select-recent', place)"
        >
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-arka-primary/10 transition group-hover:bg-arka-primary/15">
                <svg class="h-4 w-4 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-6.5 7-11.5A7 7 0 0 0 5 9.5C5 14.5 12 21 12 21Z" />
                    <circle cx="12" cy="9.5" r="2.5" />
                </svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-arka-base truncate">{{ splitAddress(place.address).title }}</span>
                <span v-if="splitAddress(place.address).subtitle" class="block text-xs text-arka-base/50 truncate">
                    {{ splitAddress(place.address).subtitle }}
                </span>
            </span>
            <svg class="h-4 w-4 shrink-0 text-arka-base/25 transition group-hover:translate-x-0.5 group-hover:text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
            </svg>
        </button>
    </div>

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
