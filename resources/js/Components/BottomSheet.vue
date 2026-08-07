<script setup>
import { onMounted, onUnmounted, watch } from 'vue';

// Modal que sube desde abajo con las esquinas superiores redondeadas —
// preferencia de diseño del usuario para menús/acciones rápidas en móvil,
// en vez de un modal centrado o un drawer lateral (sección 9.9/9.10:
// "que la web se sienta app de verdad").
const props = defineProps({
    show: {
        type: Boolean,
        default: false,
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
        document.body.style.overflow = props.show ? 'hidden' : null;
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
</script>

<template>
    <Teleport to="body">
        <Transition leave-active-class="duration-200">
            <div v-show="show" class="fixed inset-0 z-40" scroll-region>
                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div v-show="show" class="fixed inset-0 bg-black/60" @click="close" />
                </Transition>

                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="translate-y-full"
                    enter-to-class="translate-y-0"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="translate-y-0"
                    leave-to-class="translate-y-full"
                >
                    <div
                        v-show="show"
                        class="fixed inset-x-0 bottom-0 bg-arka-card rounded-t-2xl shadow-xl max-h-[80vh] overflow-y-auto sm:max-w-md sm:mx-auto"
                        style="padding-bottom: env(safe-area-inset-bottom)"
                    >
                        <!-- Agarradera visual: deja claro que es un panel que se puede deslizar -->
                        <div class="flex justify-center pt-3">
                            <div class="h-1.5 w-10 rounded-full bg-arka-text-muted/30"></div>
                        </div>

                        <slot v-if="show" />
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
