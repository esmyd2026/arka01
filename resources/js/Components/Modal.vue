<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);

watch(
    () => props.show,
    () => {
        if (props.show) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = null;
        }
    }
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const closeOnEscape = (e) => {
    if (e.key === 'Escape' && props.show) {
        close();
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));

onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = null;
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth];
});
</script>

<template>
    <Teleport to="body">
        <Transition leave-active-class="duration-200">
            <!-- Bug reportado por el usuario (captura: la alerta de confirmación
                 aparecía pegada arriba, no centrada, en una pantalla con scroll):
                 antes este contenedor solo tenía `py-6` + `mb-6` en el contenido,
                 sin centrado vertical real. `min-h-full` + `flex items-center`
                 en el wrapper interno es el patrón que evita el problema clásico
                 de "flex centrado corta el contenido" cuando el modal es más
                 alto que la pantalla (el scroll sigue funcionando igual).
                 z-[1600] (antes z-50, pedido explícito: "que estén siempre por
                 encima de todo"): los controles propios de Leaflet llegan hasta
                 z-index 1000 (ver el mismo caso ya resuelto en
                 AddressAutocomplete.vue) — con z-50 quedaban por encima de
                 cualquier alerta que se abriera con un mapa en pantalla. -->
            <div v-show="show" class="fixed inset-0 z-[1600] overflow-y-auto" scroll-region>
                <div class="flex min-h-full items-center justify-center p-4">
                    <Transition
                        enter-active-class="ease-out duration-300"
                        enter-from-class="opacity-0"
                        enter-to-class="opacity-100"
                        leave-active-class="ease-in duration-200"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div v-show="show" class="fixed inset-0 transform transition-all" @click="close">
                            <div class="absolute inset-0 bg-gray-500 opacity-75" />
                        </div>
                    </Transition>

                    <Transition
                        enter-active-class="ease-out duration-300"
                        enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                        leave-active-class="ease-in duration-200"
                        leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                        leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    >
                        <!-- Contenido del modal en tarjeta oscura, sobre el fondo semitransparente de arriba -->
                        <div
                            v-show="show"
                            class="relative w-full bg-arka-card rounded-arka overflow-hidden shadow-xl transform transition-all"
                            :class="maxWidthClass"
                        >
                            <slot v-if="show" />
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
