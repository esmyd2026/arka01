<script setup>
import { computed } from 'vue';

// Avatar reutilizable (sección 13: la foto de perfil debe verse en navbar,
// perfiles y cualquier lugar donde se identifique a un usuario, con un
// respaldo consistente si no tiene foto — nunca una imagen rota).
const props = defineProps({
    user: { type: Object, required: true },
    sizeClass: { type: String, default: 'h-9 w-9 text-sm' },
});

const initials = computed(() =>
    (props.user?.name ?? '')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('')
);
</script>

<template>
    <div
        :class="sizeClass"
        class="rounded-full bg-arka-primary text-arka-base flex items-center justify-center font-semibold overflow-hidden shrink-0"
    >
        <img
            v-if="user?.avatar_url"
            :src="user.avatar_url"
            :alt="user.name"
            class="h-full w-full object-cover"
            referrerpolicy="no-referrer"
        />
        <template v-else>{{ initials }}</template>
    </div>
</template>
