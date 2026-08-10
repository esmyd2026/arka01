<script setup>
import { ref } from 'vue';

// Ícono "?" contextual (pedido explícito del usuario: alternativa que él
// mismo ofreció a que la guía de bienvenida siempre apareciera en el mismo
// lugar — ver Components/OnboardingTour.vue) — explica un módulo puntual ahí
// mismo donde vive, sin navegar a ningún lado. Mismo mecanismo de apertura y
// cierre que Components/Dropdown.vue (click para abrir, click afuera o
// Escape para cerrar), pero autocontenido: acá el contenido siempre es un
// texto corto, no hace falta slot. Se usa la grilla de escritorio (que ya
// vive dentro de OTRO Dropdown) — anidar dos Dropdown.vue habría peleado por
// overlay y z-index, por eso este es su propio componente chico en vez de
// reusarlo tal cual.
defineProps({
    text: { type: String, required: true },
});

const open = ref(false);

function toggle(event) {
    event.stopPropagation();
    event.preventDefault();
    open.value = !open.value;
}

function close() {
    open.value = false;
}
</script>

<template>
    <span class="relative inline-block" @click.stop.prevent>
        <button
            type="button"
            class="h-4 w-4 rounded-full bg-arka-base text-arka-text-muted text-[10px] leading-none flex items-center justify-center hover:text-arka-text hover:bg-arka-primary/20 shrink-0"
            title="¿Para qué sirve?"
            @click="toggle"
            @keydown.escape="close"
        >
            ?
        </button>

        <div v-if="open" class="fixed inset-0 z-40" @click="close" />

        <div
            v-if="open"
            class="absolute z-50 top-full mt-1 right-0 w-56 p-3 rounded-arka bg-arka-card ring-1 ring-arka-text-muted/20 shadow-lg text-xs text-arka-text-muted leading-relaxed text-start"
        >
            {{ text }}
        </div>
    </span>
</template>
