<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
});

// Íconos de sección deliberadamente monocromáticos: todos heredan el mismo
// color mediante currentColor, evitando que los emojis cambien de aspecto o
// color entre Android, iPhone y escritorio.
const paths = {
    phone: ['M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z', 'M10 18h4'],
    vehicle: ['M5 17h14l-1-6-2-4H8l-2 4-1 6Z', 'M3 13h18', 'M7 17v2m10-2v2', 'M8 13h.01M16 13h.01'],
    rates: ['M4 7h16v10H4z', 'M8 12h.01M16 12h.01', 'M12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z'],
    identity: ['M4 5h16v14H4z', 'M8 9a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z', 'M6 16c.7-1.3 1.7-2 3-2s2.3.7 3 2', 'M14 10h4m-4 3h4m-4 3h3'],
    visibility: ['M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z', 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z'],
    user: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M4 21a8 8 0 0 1 16 0'],
    settings: ['M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', 'M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2 3.46-.09-.03a1.7 1.7 0 0 0-1.8.22l-.01.01a1.7 1.7 0 0 0-.9 1.56V22h-4v-.1a1.7 1.7 0 0 0-.9-1.56 1.7 1.7 0 0 0-1.8-.22l-.09.03-2-3.46.06-.06A1.7 1.7 0 0 0 6.6 15a1.7 1.7 0 0 0-1.24-1.66L5.25 13v-4l.11-.03A1.7 1.7 0 0 0 6.6 7.3a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2-3.46.09.03a1.7 1.7 0 0 0 1.8-.22A1.7 1.7 0 0 0 11 0h4a1.7 1.7 0 0 0 .9 1.56 1.7 1.7 0 0 0 1.8.22l.09-.03 2 3.46-.06.06A1.7 1.7 0 0 0 19.4 7a1.7 1.7 0 0 0 1.24 1.66l.11.03v4l-.11.03A1.7 1.7 0 0 0 19.4 15Z'],
    referrals: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M22 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'],
    subscription: ['M3 6h18v12H3z', 'M3 10h18', 'M7 15h3'],
    security: ['M6 10h12v11H6z', 'M8 10V7a4 4 0 0 1 8 0v3', 'M12 14v3'],
};

const iconPaths = computed(() => paths[props.name] ?? paths.settings);
</script>

<template>
    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-arka-primary/10 text-arka-primary-bright">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path
                v-for="path in iconPaths"
                :key="path"
                :d="path"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </span>
</template>
