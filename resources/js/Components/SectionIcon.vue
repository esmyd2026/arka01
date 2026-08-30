<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    compact: { type: Boolean, default: false },
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
    bank: ['M5 6h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z', 'M3 10h18', 'M7 15h4'],
    building: ['M4 21V7l8-4 8 4v14', 'M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01M9 21v-3h6v3'],
    drivers: ['M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', 'M16 10a2.5 2.5 0 1 0 0-5', 'M2.5 20a5.5 5.5 0 0 1 11 0', 'M14 15.5a4.5 4.5 0 0 1 7.5 3.4'],
    car: ['M4 16l2.4-6.2A2 2 0 0 1 8.3 8.5h7.4a2 2 0 0 1 1.9 1.3L20 16', 'M4 16h16v2.5a1 1 0 0 1-1 1h-1.2a1 1 0 0 1-1-1V17H7.2v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z', 'M7.5 13h.01M16.5 13h.01'],
    directory: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M3 12h18', 'M12 3c2.3 2.4 3.5 5.4 3.5 9S14.3 18.6 12 21c-2.3-2.4-3.5-5.4-3.5-9S9.7 5.4 12 3Z'],
    calendar: ['M4 5.5h16v15H4z', 'M8 3v5M16 3v5M4 10h16', 'M8 14h3M13 14h3M8 17h3'],
    plan: ['M5 8.5 12 4l7 4.5v7L12 20l-7-4.5v-7Z', 'M5 8.5 12 13l7-4.5M12 13v7'],
    circle: ['M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM16 11a3 3 0 1 0 0-6', 'M2.5 20a5.5 5.5 0 0 1 11 0M10.5 20a5.5 5.5 0 0 1 11 0', 'M12 3v2M4.5 7l1.5 1M19.5 7 18 8'],
    heart: ['M12 21s-8-4.8-9.2-10A5.2 5.2 0 0 1 12 6a5.2 5.2 0 0 1 9.2 5c-1.2 5.2-9.2 10-9.2 10Z'],
    help: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M9.7 9a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1.2 1-1.2 1.9M12 17h.01'],
    coupon: ['M4 7h16v4a2 2 0 0 0 0 4v4H4v-4a2 2 0 0 0 0-4V7Z', 'M9 9.5v5M15 9.5v.01M15 14.5v.01'],
    route: ['M5 19c2.5-4.5 2-7 5-9s4 .5 6-3', 'M6 19h.01M17 6h.01', 'M4 19a2 2 0 1 0 4 0 2 2 0 0 0-4 0ZM15 6a2 2 0 1 0 4 0 2 2 0 0 0-4 0Z'],
    survey: ['M7 3h10v4H7z', 'M5 5h2m10 0h2v16H5V5', 'M8 11h8M8 15h8M8 19h5'],
};

const iconPaths = computed(() => paths[props.name] ?? paths.settings);
</script>

<template>
    <span
        class="grid shrink-0 place-items-center bg-arka-primary/10 text-arka-primary-bright"
        :class="compact ? 'h-9 w-9 rounded-full' : 'h-10 w-10 rounded-xl'"
    >
        <svg :class="compact ? 'h-[18px] w-[18px]' : 'h-5 w-5'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
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
