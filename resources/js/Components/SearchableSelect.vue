<script setup>
import { computed, nextTick, ref } from 'vue';

// Combobox con buscador, con el estilo oscuro de la app (consideración
// agregada al alcance: el <select> nativo del navegador se ve blanco/con el
// estilo del sistema operativo — imposible de re-diseñar desde CSS, así que
// se reemplaza por este panel propio). Reutilizable en cualquier catálogo
// largo (ciudades, sectores, etc.), no solo en el formulario de carrera.
const props = defineProps({
    modelValue: { type: [Number, String, null], default: null },
    // [{ value, label, shortLabel? }] — shortLabel es opcional: si se pasa,
    // es lo que se muestra en el botón ya cerrado (pedido explícito del
    // usuario: el selector de código de país ocupaba demasiado espacio en
    // móvil con el nombre completo del país) mientras que la lista
    // desplegable sigue mostrando el label completo, más fácil de buscar.
    options: { type: Array, required: true },
    placeholder: { type: String, default: 'Elegí una opción' },
    // Texto de la opción "vacía" (ej. "Sin especificar") — si no se pasa, no
    // se ofrece la opción de dejarlo sin elegir.
    emptyLabel: { type: String, default: null },
    // Para que <InputLabel for="..."> siga enfocando el control real al
    // hacer clic en la etiqueta, como con un <select> nativo — atributo
    // explícito en vez de fallthrough automático, porque ese hubiera caído
    // en el <div> contenedor, no en el botón.
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const search = ref('');
const buttonEl = ref(null);
const searchEl = ref(null);

const selectedOption = computed(() => props.options.find((o) => o.value === props.modelValue) ?? null);

const filteredOptions = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.options;
    return props.options.filter((o) => o.label.toLowerCase().includes(term));
});

async function toggle() {
    open.value = !open.value;
    if (open.value) {
        search.value = '';
        await nextTick();
        searchEl.value?.focus();
    }
}

function select(value) {
    emit('update:modelValue', value);
    open.value = false;
    buttonEl.value?.focus();
}

function close() {
    open.value = false;
}
</script>

<template>
    <div class="relative">
        <button
            :id="id"
            ref="buttonEl"
            type="button"
            class="w-full flex items-center justify-between gap-2 rounded-arka border border-arka-text-muted/20 bg-arka-base px-3 py-2 text-start text-arka-text focus:outline-none focus:ring-2 focus:ring-arka-primary"
            @click="toggle"
            @keydown.escape="close"
        >
            <span :class="{ 'text-arka-text-muted': !selectedOption }" class="truncate">
                {{ selectedOption ? (selectedOption.shortLabel ?? selectedOption.label) : emptyLabel ?? placeholder }}
            </span>
            <svg class="h-4 w-4 shrink-0 text-arka-text-muted transition-transform" :class="{ 'rotate-180': open }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </button>

        <div v-if="open" class="fixed inset-0 z-40" @click="close" />

        <!-- Bug real reportado por el usuario (con captura): el panel abierto
             heredaba el mismo ancho que el botón (w-full = 100% del propio
             selector) — con un selector angosto a propósito (ej. el código de
             país, w-28) el buscador y los nombres completos de la lista
             quedaban aplastados y se veían pegados con el contenido de al
             lado. min-w-56 les da aire sin importar qué tan angosto sea el
             botón que lo abre; max-w-[90vw] evita que se salga de la pantalla
             en un celular muy chico. -->
        <div
            v-if="open"
            class="absolute z-50 mt-1 w-full min-w-56 max-w-[90vw] max-h-64 overflow-hidden rounded-arka border border-arka-text-muted/20 bg-arka-card shadow-lg flex flex-col"
        >
            <div class="p-2 border-b border-arka-text-muted/10">
                <input
                    ref="searchEl"
                    v-model="search"
                    type="text"
                    placeholder="Buscar…"
                    class="w-full rounded-arka border border-arka-text-muted/20 bg-arka-base px-2.5 py-1.5 text-sm text-arka-text placeholder:text-arka-text-muted focus:outline-none focus:ring-2 focus:ring-arka-primary"
                    @keydown.escape="close"
                />
            </div>

            <ul class="overflow-y-auto py-1" role="listbox">
                <li v-if="emptyLabel">
                    <button
                        type="button"
                        class="w-full px-3 py-2 text-start text-sm hover:bg-arka-base"
                        :class="modelValue === null ? 'text-arka-primary-bright' : 'text-arka-text-muted'"
                        @click="select(null)"
                    >
                        {{ emptyLabel }}
                    </button>
                </li>
                <li v-for="option in filteredOptions" :key="option.value">
                    <button
                        type="button"
                        class="w-full px-3 py-2 text-start text-sm hover:bg-arka-base"
                        :class="option.value === modelValue ? 'text-arka-primary-bright font-medium' : 'text-arka-text'"
                        @click="select(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </li>
                <li v-if="!filteredOptions.length" class="px-3 py-2 text-sm text-arka-text-muted">
                    Sin resultados.
                </li>
            </ul>
        </div>
    </div>
</template>
