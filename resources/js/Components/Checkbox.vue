<script setup>
import { computed } from 'vue';

const emit = defineEmits(['update:checked']);

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        required: true,
    },
    value: {
        default: null,
    },
});

const proxyChecked = computed({
    get() {
        return props.checked;
    },

    set(val) {
        emit('update:checked', val);
    },
});
</script>

<template>
    <!-- Checkbox oscuro: cuando está marcado se rellena con el verde primario de la marca -->
    <input
        type="checkbox"
        :value="value"
        v-model="proxyChecked"
        class="rounded border-arka-text-muted/30 bg-arka-card text-arka-primary shadow-sm focus:ring-arka-primary"
    />
</template>
